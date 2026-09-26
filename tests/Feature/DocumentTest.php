<?php

declare(strict_types=1);

use App\Enums\DocumentStatus;
use App\Enums\PaymentTerm;
use App\Enums\Unit;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\LineItem;
use Brick\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('creates a draft invoice for a customer of its own company', function (): void {
    $company = Company::factory()->create();
    $invoice = Invoice::factory()->for($company)->create();

    expect($invoice->company_id)->toBe($company->getKey())
        ->and($invoice->customer?->company_id)->toBe($company->getKey())
        ->and($invoice->status)->toBe(DocumentStatus::Draft)
        ->and($invoice->number)->toBeNull();
});

it('stamps the type itself, so nothing has to remember to', function (): void {
    // Parental force-fills the discriminator on create. If it ever stopped,
    // Invoice::query() would return nothing and the list would look empty.
    $invoice = Invoice::factory()->create();

    expect(DB::table('documents')->where('id', $invoice->getKey())->value('type'))->toBe('invoice');
});

it('returns a document as the class its type names', function (): void {
    // The whole reason for the single table: Document::query() hands back an
    // Invoice, so a later Storno is a class rather than a branch.
    $invoice = Invoice::factory()->create();

    $found = Document::query()->whereKey($invoice->getKey())->first();

    expect($found)->toBeInstanceOf(Invoice::class);
});

it('keeps Invoice::query() to invoices', function (): void {
    // Written straight to the table, because no second type exists yet to
    // create through a model — which is exactly when this scope is easiest to
    // get wrong and hardest to notice.
    $invoice = Invoice::factory()->create();

    DB::table('documents')->insert([
        ...(array) DB::table('documents')->where('id', $invoice->getKey())->first(),
        'id' => (string) Str::uuid7(),
        'type' => 'cancellation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('documents')->count())->toBe(2)
        ->and(Invoice::query()->count())->toBe(1);
});

it('carries its Positionen and gives them up when it goes', function (): void {
    $invoice = Invoice::factory()->create();
    LineItem::factory()->for($invoice, 'document')->count(3)->create();

    expect($invoice->lineItems()->count())->toBe(3);

    $invoice->delete();

    expect(LineItem::query()->count())->toBe(0);
});

it('reads a Position back as the values it was typed with', function (): void {
    // A Position references no master data (§3.6): the unit code and the tax
    // rate are copied values, so a later rename cannot reach into a document.
    $invoice = Invoice::factory()->create();

    $line = LineItem::factory()->for($invoice, 'document')->create([
        'position' => 1,
        'title' => 'Konzeption Netzwerkplanung',
        'quantity' => '12',
        'unit' => Unit::Hour,
        'unit_price' => Money::of('95.00', 'EUR'),
        'tax_rate' => 1900,
    ]);

    $fresh = $line->fresh();

    expect($fresh?->unit)->toBe(Unit::Hour)
        ->and((string) $fresh?->unit_price->getAmount())->toBe('95.00')
        ->and($fresh?->tax_rate)->toBe(1900)
        ->and((string) $fresh?->quantity)->toBe('12.000');
});

it('stores the Einzelpreis as a bigint of cents', function (): void {
    // Asserted against the column, not the cast: a cast over a double
    // precision column round-trips 95,00 happily and loses a cent elsewhere.
    $invoice = Invoice::factory()->create();
    LineItem::factory()->for($invoice, 'document')->create(['unit_price' => Money::of('95.00', 'EUR')]);

    $column = DB::selectOne(
        'select data_type from information_schema.columns where table_name = ? and column_name = ?',
        ['line_items', 'unit_price']
    );

    expect($column?->data_type)->toBe('bigint')
        ->and(DB::table('line_items')->value('unit_price'))->toBe(9500);
});

it('takes the Zahlungsziel onto the document rather than reading it through the customer', function (): void {
    // The Zahlungsziel is part of what issuing freezes. A customer who moves
    // from 14 to 30 days must not change an invoice already written.
    $company = Company::factory()->create(['payment_term' => PaymentTerm::Net14]);
    $customer = Customer::factory()->for($company)->create(['payment_term' => PaymentTerm::Net7]);
    $invoice = Invoice::factory()->for($company)->create([
        'customer_id' => $customer->getKey(),
        'payment_term' => $customer->effectivePaymentTerm(),
    ]);

    $customer->update(['payment_term' => PaymentTerm::Net30]);

    expect($invoice->fresh()?->payment_term)->toBe(PaymentTerm::Net7);
});

it('lets many drafts share the absence of a number', function (): void {
    // unique(company_id, number) with nulls: PostgreSQL treats them as
    // distinct, which is what lets the index exist before the numbering does.
    $company = Company::factory()->create();

    Invoice::factory()->for($company)->count(3)->create();

    expect(Invoice::query()->whereNull('number')->count())->toBe(3);
});
