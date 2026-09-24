<?php

declare(strict_types=1);

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Exceptions\CannotArchiveLastCompany;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('gives companies a real uuid column, not a bigint', function (): void {
    // Asserting that $company->id is a string would pass against a bigint too —
    // PDO hands integers back as strings. The only assertion that can fail is
    // the type the server itself reports for the column.
    $column = DB::selectOne(
        'select data_type from information_schema.columns
         where table_name = ? and column_name = ?',
        ['companies', 'id']
    );

    expect($column->data_type)->toBe('uuid');
});

it('generates version 7 uuids, so keys stay time-ordered', function (): void {
    $company = Company::factory()->create();

    expect($company->getIncrementing())->toBeFalse()
        ->and($company->getKeyType())->toBe('string')
        ->and(Str::isUuid($company->getKey()))->toBeTrue();

    // The version nibble sits at index 14. It is the discriminator between v7
    // and v4: both satisfy every assertion above, only v7 is time-ordered.
    expect($company->getKey()[14])->toBe('7');
});

it('derives the slug from the name', function (): void {
    $company = Company::factory()->create(['name' => 'Ölmühle Müller GmbH']);

    expect($company->slug)->toBe('olmuhle-muller-gmbh');
});

it('suffixes a colliding slug so both companies stay reachable', function (): void {
    $first = Company::factory()->create(['name' => 'Acme GmbH']);
    $second = Company::factory()->create(['name' => 'Acme GmbH']);

    expect($first->slug)->toBe('acme-gmbh')
        ->and($second->slug)->toBe('acme-gmbh-2');
});

it('keeps counting past a slug that already carries a suffix', function (): void {
    // The naive implementation appends "-2" and stops. Here "acme-gmbh-2" is
    // already taken by a differently named company, so a second "Acme GmbH"
    // must land on -3 rather than violating the unique index.
    Company::factory()->create(['name' => 'Acme GmbH']);
    Company::factory()->create(['name' => 'Acme GmbH 2']);

    $third = Company::factory()->create(['name' => 'Acme GmbH']);

    expect($third->slug)->toBe('acme-gmbh-3');
});

it('still produces a usable slug when the name has nothing to slug', function (): void {
    // Str::slug('&&&') is the empty string. Left alone that yields a company
    // with no reachable URL, and a unique-constraint violation on the second.
    $first = Company::factory()->create(['name' => '&&&']);
    $second = Company::factory()->create(['name' => '+++']);

    expect($first->slug)->toBe('company')
        ->and($second->slug)->toBe('company-2');
});

it('leaves the slug alone when the company is renamed', function (): void {
    // A regression guard. Regenerating the slug on rename is a common thing to
    // reach for, and it silently breaks every bookmarked URL.
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    $company->update(['name' => 'Acme Software GmbH']);

    expect($company->fresh()?->slug)->toBe('acme-gmbh');
});

it('routes companies by slug rather than by uuid', function (): void {
    // Filament builds tenant URLs with route(..., ['tenant' => $company]),
    // which calls getRouteKey(). Without this override the switcher emits
    // /admin/{uuid}, which then fails to resolve because tenant
    // identification queries the slug column.
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    expect($company->getRouteKeyName())->toBe('slug')
        ->and($company->getRouteKey())->toBe('acme-gmbh');
});

it('starts a new company on the standard vat scheme', function (): void {
    // The registration form asks for a name and a legal form only, so anything
    // else the column requires has to have a default or creation fails.
    $company = Company::query()->create([
        'name' => 'Acme GmbH',
        'legal_form' => LegalForm::GmbH,
    ]);

    expect($company->vat_scheme)->toBe(VatScheme::Standard);
});

it('knows which legal forms are entered in the commercial register', function (): void {
    expect(LegalForm::GmbH->isRegistered())->toBeTrue()
        ->and(LegalForm::UG->isRegistered())->toBeTrue()
        ->and(LegalForm::SoleProprietorship->isRegistered())->toBeFalse();
});

it('resolves a german label for every enum case', function (): void {
    // A missing key makes trans() return the key itself, so this fails on a
    // forgotten lang entry rather than merely restating the lang file.
    foreach (LegalForm::cases() as $case) {
        expect($case->getLabel())->not->toContain('company.');
    }

    foreach (VatScheme::cases() as $case) {
        expect($case->getLabel())->not->toContain('company.');
    }
});

it('reports a fresh company as not archived', function (): void {
    expect(Company::factory()->create()->isArchived())->toBeFalse()
        ->and(Company::factory()->archived()->create()->isArchived())->toBeTrue();
});

it('archives a company while another active one remains', function (): void {
    $keep = Company::factory()->create();
    $company = Company::factory()->create();

    $company->archive();

    expect($company->fresh()?->isArchived())->toBeTrue()
        ->and($keep->fresh()?->isArchived())->toBeFalse();
});

it('refuses to archive the last active company', function (): void {
    // Archiving it is a dead end: with no tenants left, Filament redirects to
    // company registration, and the companies list is itself a screen behind
    // the tenant prefix — so there would be no route left from which to
    // restore anything.
    $only = Company::factory()->create();

    expect(fn (): mixed => $only->archive())
        ->toThrow(CannotArchiveLastCompany::class);

    expect($only->fresh()?->isArchived())->toBeFalse();
});

it('does not count already-archived companies as the ones keeping the lights on', function (): void {
    // Two rows, one already archived: archiving the survivor is still the
    // dead end the guard exists to prevent.
    Company::factory()->archived()->create();
    $survivor = Company::factory()->create();

    expect($survivor->canBeArchived())->toBeFalse();
});

it('unarchives a company', function (): void {
    $company = Company::factory()->archived()->create();

    $company->unarchive();

    expect($company->fresh()?->isArchived())->toBeFalse();
});

it('reports an already-archived company as not archivable again', function (): void {
    Company::factory()->create();
    $archived = Company::factory()->archived()->create();

    expect($archived->canBeArchived())->toBeFalse();
});
