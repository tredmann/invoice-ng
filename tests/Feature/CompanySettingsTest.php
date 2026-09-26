<?php

declare(strict_types=1);

use App\Actions\DrawNextNumber;
use App\Enums\LegalForm;
use App\Enums\PaymentTerm;
use App\Enums\VatScheme;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Models\Company;
use App\Models\NumberRange;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A user linked to every company passed in.
 *
 * @param  array<int, Company>  $companies
 */
function userOf(array $companies): User
{
    $user = User::factory()->create();
    $user->companies()->attach(collect($companies)->map->getKey()->all());

    return $user;
}

it('serves a company settings page under the company slug', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    $this->actingAs(userOf([$company]))
        ->get('/admin/acme-gmbh/settings')
        ->assertOk()
        ->assertSee('Acme GmbH');
});

it('shows the company named in the url, not the one in the session', function (): void {
    /** @var TestCase $this */
    // The load-bearing test for §3.2. A session-only implementation passes any
    // single-request version of this: it only fails once a second company is
    // requested in the same session and the first one's data comes back.
    $first = Company::factory()->create(['name' => 'Erste GmbH', 'city' => 'Hamburg']);
    $second = Company::factory()->create(['name' => 'Zweite GmbH', 'city' => 'Rosenheim']);
    $user = userOf([$first, $second]);

    $this->actingAs($user);

    $this->get('/admin/erste-gmbh/settings')
        ->assertOk()
        ->assertSee('Hamburg')
        ->assertDontSee('Rosenheim');

    $this->get('/admin/zweite-gmbh/settings')
        ->assertOk()
        ->assertSee('Rosenheim')
        ->assertDontSee('Hamburg');
});

it('refuses the settings of a company the user is not linked to', function (): void {
    /** @var TestCase $this */
    $mine = Company::factory()->create(['name' => 'Mine GmbH']);
    $theirs = Company::factory()->create(['name' => 'Theirs GmbH']);

    $this->actingAs(userOf([$mine]))
        ->get('/admin/theirs-gmbh/settings')
        ->assertNotFound();
});

it('round-trips umlauts through the settings form', function (): void {
    /** @var TestCase $this */
    // This repository has already shipped a PDF in which every German
    // character was mojibake while the suite stayed green. German company
    // names and streets are the normal case here, not an edge one.
    $company = Company::factory()->create(['name' => 'Ölmühle Müller GmbH']);
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm([
            'street' => 'Grünstraße 7',
            'city' => 'Rosenheim',
            'postal_code' => '83022',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->street)->toBe('Grünstraße 7');

    $response = $this->actingAs($user)
        ->get('/admin/olmuhle-muller-gmbh/settings')
        ->assertOk();

    // Filament's TextInput never puts its value in a server-rendered `value`
    // attribute — the browser fills it in from the Livewire snapshot payload
    // instead, which PHP's json_encode() escapes to \uXXXX for any non-ASCII
    // character. assertSee() on the raw response therefore cannot tell a
    // correct umlaut from a mojibake'd one; it cannot see either. Mounting
    // the same page as a component and reading its actual state is what
    // proves the character survived the round trip intact.
    Livewire::test(CompanySettings::class)
        ->assertSet('data.street', 'Grünstraße 7');

    // The signature of UTF-8 bytes served as Latin-1 — the exact corruption
    // this repository has shipped before. It is encoding-agnostic about where
    // the value appears on the page, so it survives Livewire escaping the
    // snapshot JSON, and it cannot produce a false failure on correct output.
    $response->assertDontSee('Ã');

    // The company's own name, which the panel chrome renders as HTML text
    // rather than as snapshot JSON.
    $response->assertSee('Ölmühle Müller GmbH');
});

it('requires the register fields of a company that is in the handelsregister', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm([
            'legal_form' => LegalForm::GmbH->value,
            'register_court' => '',
            'register_number' => '',
            'managing_directors' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['register_court', 'register_number', 'managing_directors']);
});

it('saves a sole proprietorship with no register fields at all', function (): void {
    // The other direction. Without it, the test above cannot tell "required
    // for a GmbH" from "required always".
    $company = Company::factory()->soleProprietorship()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['legal_form' => LegalForm::SoleProprietorship->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->register_number)->toBeNull();
});

it('clears register data when a company stops being registered', function (): void {
    // The fields are hidden for a sole proprietorship, and a hidden Filament
    // field is not dehydrated — so without this the old HRB number would sit
    // in the database and print on the next document.
    $company = Company::factory()->create([
        'register_court' => 'Amtsgericht München',
        'register_number' => 'HRB 12345',
        'managing_directors' => 'Tobias Redmann',
    ]);
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['legal_form' => LegalForm::SoleProprietorship->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $company->fresh();

    expect($fresh?->register_court)->toBeNull()
        ->and($fresh?->register_number)->toBeNull()
        ->and($fresh?->managing_directors)->toBeNull();
});

it('insists on at least one tax identifier', function (): void {
    // Which of the two a company has depends on its VAT scheme, so neither can
    // be required on its own — but a company with neither cannot issue a legal
    // invoice.
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['tax_number' => '', 'vat_id' => ''])
        ->call('save')
        ->assertHasFormErrors(['tax_number', 'vat_id']);
});

it('accepts a company with only a steuernummer', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['tax_number' => '143/815/08151', 'vat_id' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->vat_id)->toBeNull();
});

it('accepts a company with only a vat id', function (): void {
    // The mirror of the test above, and not redundant with it: with only the
    // steuernummer case covered, making tax_number unconditionally required
    // would go unnoticed, because every other test either leaves it empty or
    // always supplies it. A company on the standard VAT scheme commonly holds
    // the USt-IdNr and not the Steuernummer.
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['tax_number' => '', 'vat_id' => 'DE123456789'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->tax_number)->toBeNull();
});

it('rejects an invalid iban on the settings form', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['iban' => 'DE98370400440532013000'])
        ->call('save')
        ->assertHasFormErrors(['iban']);
});

it('never lets the slug be edited', function (): void {
    // The slug is a stable public identifier. If the form could write it, a
    // rename would break every bookmarked URL.
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = userOf([$company]);

    // Livewire::actingAs() authenticates immediately as a side effect; it
    // has to run before Filament::setTenant(), because the tenant-set event
    // requires an already-authenticated user.
    Livewire::actingAs($user);

    Filament::setTenant($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['slug' => 'something-else'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->slug)->toBe('acme-gmbh');
});

/**
 * Signs in and makes $company the tenant, for the tabbed settings form.
 *
 * @return Testable<CompanySettings>
 */
function settingsFormFor(Company $company): Testable
{
    Livewire::actingAs(userOf([$company]));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);

    return Livewire::test(CompanySettings::class);
}

it('saves the Zahlungsziel from the bank tab', function (): void {
    $company = Company::factory()->create();

    settingsFormFor($company)
        ->fillForm(['payment_term' => PaymentTerm::Net30->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->payment_term)->toBe(PaymentTerm::Net30);
});

it('creates the Nummernkreis the first time the tab is saved', function (): void {
    // The row is absent until now — that is what lets the Bereitschaftsprüfung
    // report it missing rather than always finding a default someone invented.
    $company = Company::factory()->create();

    expect($company->numberRange()->exists())->toBeFalse();

    settingsFormFor($company)
        ->fillForm([
            'number_range' => [
                'prefix' => 'AR-',
                'padding' => 5,
                'next_value' => 43,
                'include_year' => false,
                'reset_yearly' => false,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $range = $company->fresh()?->numberRange()->sole();

    expect($range?->prefix)->toBe('AR-')
        ->and($range?->padding)->toBe(5)
        ->and($range?->next_value)->toBe(43)
        ->and($range?->nextNumber())->toBe('AR-00043');
});

it('previews the next number without drawing one', function (): void {
    // A preview built by calling DrawNextNumber would look identical on screen
    // and burn a Belegnummer on every page load.
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create([
        'prefix' => 'RE-', 'padding' => 4, 'next_value' => 43, 'include_year' => false,
    ]);

    settingsFormFor($company)->assertSee('RE-0043');

    $range = $company->numberRange()->sole();

    expect($range->drawn_count)->toBe(0)
        ->and($range->next_value)->toBe(43);
});

it('refuses to lower the Startwert once a number has been drawn', function (): void {
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create([
        'prefix' => 'RE-', 'padding' => 4, 'next_value' => 100, 'include_year' => false,
    ]);
    DB::transaction(fn (): string => (new DrawNextNumber)($company));

    settingsFormFor($company)
        ->fillForm(['number_range' => [
            'prefix' => 'RE-', 'padding' => 4, 'next_value' => 50,
            'include_year' => false, 'reset_yearly' => true,
        ]])
        ->call('save')
        ->assertHasFormErrors(['number_range.next_value']);

    expect($company->numberRange()->sole()->next_value)->toBe(101);
});

it('still allows the Startwert to be raised after a draw', function (): void {
    // One direction alone cannot tell "guarded" from "always refused".
    $company = Company::factory()->create();
    NumberRange::factory()->for($company)->create([
        'prefix' => 'RE-', 'padding' => 4, 'next_value' => 100, 'include_year' => false,
    ]);
    DB::transaction(fn (): string => (new DrawNextNumber)($company));

    settingsFormFor($company)
        ->fillForm(['number_range' => [
            'prefix' => 'RE-', 'padding' => 4, 'next_value' => 5000,
            'include_year' => false, 'reset_yearly' => true,
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->numberRange()->sole()->next_value)->toBe(5000);
});

it('edits the Steuersätze through the tax tab', function (): void {
    $company = Company::factory()->create();
    $standard = $company->taxRates()->where('rate', 1900)->sole();

    settingsFormFor($company)
        ->fillForm(['tax_rates' => [
            ['id' => $standard->getKey(), 'rate' => '19', 'name' => 'Regelsatz', 'is_default' => false],
            ['id' => null, 'rate' => '7,5', 'name' => 'Sondersatz', 'is_default' => true],
        ]])
        ->call('save')
        ->assertHasNoFormErrors();

    $rates = $company->taxRates()->whereNull('deactivated_at')->orderByDesc('rate')->get();

    expect($rates->pluck('rate')->all())->toBe([1900, 750])
        ->and($rates->where('is_default', true)->pluck('rate')->all())->toBe([750]);
});

it('hides the Steuersätze from a Kleinunternehmer and says why', function (): void {
    $company = Company::factory()->create(['vat_scheme' => VatScheme::SmallBusiness]);

    settingsFormFor($company)
        ->assertDontSee('Steuersatz hinzufügen')
        ->assertSee('ohne USt-Block');
});

it('shows the Steuersätze to a company on the standard scheme', function (): void {
    // The other direction: without it, a card hidden by a mistake in the
    // visibility closure would look exactly like a card hidden on purpose.
    $company = Company::factory()->create();

    settingsFormFor($company)->assertSee('Steuersatz hinzufügen');
});

it('puts the only save action at the far right', function (): void {
    /** @var TestCase $this */
    // Filament's default is Alignment::Start, so this fails against a page that
    // simply does not say — which is what it was doing. There is no cancel here
    // to separate: the rule for a page that saves in place is one action, far
    // right (.ai/guidelines/ui/core.blade.php).
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    $html = (string) $this->actingAs(userOf([$company]))
        ->get('/admin/acme-gmbh/settings')
        ->assertOk()
        ->getContent();

    // Scoped to the action row: the page carries other aligned elements, and an
    // unscoped search would pass on any of them.
    $actions = (string) str($html)->after('form-actions');

    expect($actions)->toContain('fi-align-end')
        ->and($actions)->not->toContain('fi-align-start');
});

it('sets the next Belegnummer apart in its own box', function (): void {
    /** @var TestCase $this */
    // It is the one thing on the Nummernkreis tab that is an answer rather than
    // a setting. A bare placeholder reads as another field's value.
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    NumberRange::factory()->for($company)->create([
        'prefix' => 'RE-', 'padding' => 4, 'next_value' => 43, 'include_year' => false,
    ]);

    $html = (string) $this->actingAs(userOf([$company]))
        ->get('/admin/acme-gmbh/settings')
        ->assertOk()
        ->getContent();

    // The whole box in one assertion: a label and a value inside one
    // app-number-preview element. Asserting the two separately would pass
    // against a layout that put them in different places on the page.
    expect($html)->toMatch(
        '/<div class="app-number-preview">'
        .'<span class="app-number-preview-label">Nächste Nummer<\/span>'
        .'<span class="app-number-preview-value">RE-0043<\/span>'
        .'<\/div>/u'
    );

    // And the classes have to resolve to something: there is no CSS build, so
    // a class Filament does not ship is invisible unless panel-styles says so.
    expect($html)->toContain('.app-number-preview-value');
});

it('puts the Handelsregister fields side by side', function (): void {
    /** @var TestCase $this */
    // The register card is the only two-column grid a GmbH has that a sole
    // proprietorship does not, so the difference between the two pages is
    // exactly that card. Stacking it again drops the count to match.
    $gmbh = Company::factory()->create(['name' => 'Acme GmbH']);
    $sole = Company::factory()->soleProprietorship()->create(['name' => 'Bea Weber']);

    $withRegister = (string) $this->actingAs(userOf([$gmbh]))
        ->get('/admin/acme-gmbh/settings')->assertOk()->getContent();
    $withoutRegister = (string) $this->actingAs(userOf([$sole]))
        ->get('/admin/bea-weber/settings')->assertOk()->getContent();

    expect(substr_count($withRegister, '--cols-lg: repeat(2'))
        ->toBe(substr_count($withoutRegister, '--cols-lg: repeat(2') + 1);
});
