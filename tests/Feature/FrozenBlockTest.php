<?php

declare(strict_types=1);

use App\Casts\FrozenBlockCast;
use App\Documents\BuyerAddress;
use App\Documents\FrozenBlock;
use App\Documents\SellerIdentity;
use App\Enums\DocumentStatus;
use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * The Festschreibung (§4). What makes these fail is a block that reaches back
 * into live Stammdaten, or one that can be written half-formed into a column
 * nothing is ever allowed to correct.
 */
it('copies the company and the customer as they read now', function (): void {
    $company = Company::factory()->create([
        'name' => 'Musterbetrieb',
        'legal_form' => LegalForm::GmbH,
        'street' => 'Musterstraße 1',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'tax_number' => '29/123/45678',
        'register_court' => 'Amtsgericht Charlottenburg',
        'register_number' => 'HRB 123456',
        'managing_directors' => 'Erika Mustermann',
        'iban' => 'DE02120300000000202051',
    ]);

    $customer = Customer::factory()->for($company)->create([
        'name' => 'Bauer & Kollegen GmbH',
        'street' => 'Kundenweg 7',
        'postal_code' => '20095',
        'city' => 'Hamburg',
    ]);

    $block = FrozenBlock::of($company, $customer);

    expect($block->seller->name)->toBe('Musterbetrieb')
        ->and($block->seller->city)->toBe('Berlin')
        ->and($block->seller->taxNumber)->toBe('29/123/45678')
        ->and($block->seller->registerNumber)->toBe('HRB 123456')
        ->and($block->buyer->name)->toBe('Bauer & Kollegen GmbH')
        ->and($block->buyer->city)->toBe('Hamburg')
        // The Kundennummer is frozen as the string the customer quotes back,
        // not as the integer behind it.
        ->and($block->buyer->number)->toBe($customer->formattedNumber());
});

it('freezes DE as the country on both addresses', function (): void {
    // EN16931 makes a country code mandatory on both postal addresses (BR-09,
    // BR-11) and neither table carries one. If this ever becomes a column, this
    // test is what says the hardcoding was deliberate rather than forgotten.
    [$company, $customer] = companyAndCustomer();
    $block = FrozenBlock::of($company, $customer);

    expect($block->seller->country)->toBe('DE')
        ->and($block->buyer->country)->toBe('DE');
});

it('reads an empty master data field as absent, not as an empty string', function (): void {
    // A blank BIC printing as an empty line under the bank details is the
    // symptom; a '' that is not === null everywhere downstream is the cause.
    [$company, $customer] = companyAndCustomer();
    $company->forceFill(['bic' => '  ', 'vat_id' => ''])->save();
    $customer->forceFill(['contact_person' => ' '])->save();

    $block = FrozenBlock::of($company->fresh() ?? $company, $customer->fresh() ?? $customer);

    expect($block->seller->bic)->toBeNull()
        ->and($block->seller->vatId)->toBeNull()
        ->and($block->buyer->contactPerson)->toBeNull();
});

it('round-trips through the cast unchanged', function (): void {
    [$company, $customer] = companyAndCustomer();
    $block = FrozenBlock::of($company, $customer);

    $cast = new FrozenBlockCast;
    $json = $cast->set(new Invoice, 'frozen_block', $block, []);
    $read = $cast->get(new Invoice, 'frozen_block', $json, []);

    expect($read)->toBeInstanceOf(FrozenBlock::class)
        ->and($read?->toArray())->toBe($block->toArray());
});

it('survives an umlaut through the json column', function (): void {
    // JSON_UNESCAPED_UNICODE plus jsonb is the pair that decides this. A
    // Straße that came back as an escaped ASCII sequence would still be
    // readable in PHP and unreadable to anyone querying the column by hand.
    //
    // Written in one save on a draft, because that is the only shape the
    // immutability guard permits — and it is the shape IssueDocument uses.
    [$company, $customer] = companyAndCustomer();
    $invoice = Invoice::factory()->for($company)->create(['customer_id' => $customer->getKey()]);
    $block = FrozenBlock::of($company, $customer);

    $invoice->forceFill(['frozen_block' => $block, 'status' => DocumentStatus::Issued])->save();

    expect($invoice->fresh()?->frozen_block?->seller->street)->toBe($block->seller->street)
        ->and(DB::table('documents')->where('id', $invoice->getKey())->value('frozen_block'))
        ->toContain('Musterstraße');
});

it('refuses to write anything but a FrozenBlock', function (mixed $value): void {
    // The column is the one nothing may correct afterwards: Document's guard
    // refuses the update that would fix a half-written block. An array here
    // would let a caller write one.
    expect(fn (): ?string => (new FrozenBlockCast)->set(new Invoice, 'frozen_block', $value, []))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'array' => [['seller' => [], 'buyer' => []]],
    'string' => ['{}'],
    'int' => [1],
]);

it('refuses to read a block that is missing a party', function (): void {
    [$company, $customer] = companyAndCustomer();
    $payload = FrozenBlock::of($company, $customer)->toArray();
    unset($payload['buyer']);

    expect(fn (): ?FrozenBlock => (new FrozenBlockCast)->get(new Invoice, 'frozen_block', json_encode($payload), []))
        ->toThrow(InvalidArgumentException::class, 'buyer');
});

it('refuses to read a block whose seller address is missing', function (): void {
    // Reading a broken record loudly is the point: a silent null would print an
    // invoice with no seller address on it, which is the §14 UStG failure the
    // whole readiness check exists to prevent.
    [$company, $customer] = companyAndCustomer();
    $payload = FrozenBlock::of($company, $customer)->toArray();
    unset($payload['seller']['city']);

    expect(fn (): ?FrozenBlock => (new FrozenBlockCast)->get(new Invoice, 'frozen_block', json_encode($payload), []))
        ->toThrow(InvalidArgumentException::class, 'city');
});

it('refuses to read malformed json', function (): void {
    expect(fn (): ?FrozenBlock => (new FrozenBlockCast)->get(new Invoice, 'frozen_block', '{not json', []))
        ->toThrow(InvalidArgumentException::class);
});

it('names a registered company with its legal form and an Einzelunternehmen without one', function (LegalForm $form, string $name, string $expected): void {
    // §35a GmbHG makes the designation part of a GmbH's legal name; an
    // Einzelunternehmen has none to append. The third case is the one that
    // actually happens: the settings form asks for the name and the form
    // separately, so 'Acme GmbH' typed into the name field is likely.
    $seller = new SellerIdentity(
        name: $name,
        legalForm: $form,
        street: 'Musterstraße 1',
        postalCode: '10115',
        city: 'Berlin',
        country: 'DE',
        taxNumber: '29/123/45678',
        vatId: null,
        registerCourt: null,
        registerNumber: null,
        managingDirectors: null,
        bankName: null,
        iban: null,
        bic: null,
        vatScheme: VatScheme::Standard,
    );

    expect($seller->legalName())->toBe($expected);
})->with([
    'GmbH' => [LegalForm::GmbH, 'Acme', 'Acme GmbH'],
    'UG' => [LegalForm::UG, 'Acme', 'Acme UG (haftungsbeschränkt)'],
    'Einzelunternehmen' => [LegalForm::SoleProprietorship, 'Erika Mustermann', 'Erika Mustermann'],
    'already named' => [LegalForm::GmbH, 'Acme GmbH', 'Acme GmbH'],
]);

it('prefers the USt-IdNr over the Steuernummer as the tax identifier', function (): void {
    // Both may be present; EN16931 wants the VAT ID where there is one, and
    // §14 UStG accepts either.
    [$company, $customer] = companyAndCustomer();
    $company->forceFill(['tax_number' => '29/123/45678', 'vat_id' => 'DE811907980'])->save();

    $seller = SellerIdentity::of($company->fresh() ?? $company);

    expect($seller->taxIdentifier())->toBe('DE811907980');
});

it('keeps the buyer address of a customer who later moves', function (): void {
    // The test that makes freezing mean something at the DTO level; the
    // end-to-end version lives in IssueDocumentTest.
    [$company, $customer] = companyAndCustomer();
    $block = FrozenBlock::of($company, $customer);

    $customer->forceFill(['street' => 'Woanders 9', 'city' => 'Köln'])->save();

    expect($block->buyer->city)->toBe('Hamburg')
        ->and(BuyerAddress::of($customer->fresh() ?? $customer)->city)->toBe('Köln');
});

/**
 * @return array{Company, Customer}
 */
function companyAndCustomer(): array
{
    $company = Company::factory()->create([
        'name' => 'Musterbetrieb',
        'legal_form' => LegalForm::GmbH,
        'street' => 'Musterstraße 1',
        'postal_code' => '10115',
        'city' => 'Berlin',
        'tax_number' => '29/123/45678',
        'register_court' => 'Amtsgericht Charlottenburg',
        'register_number' => 'HRB 123456',
        'managing_directors' => 'Erika Mustermann',
        'bank_name' => 'Musterbank',
        'iban' => 'DE02120300000000202051',
        'bic' => 'BYLADEM1001',
    ]);

    $customer = Customer::factory()->for($company)->create([
        'name' => 'Bauer & Kollegen GmbH',
        'street' => 'Kundenweg 7',
        'postal_code' => '20095',
        'city' => 'Hamburg',
    ]);

    return [$company, $customer];
}
