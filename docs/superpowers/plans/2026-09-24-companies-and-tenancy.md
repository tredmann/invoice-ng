# Companies and Tenancy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver companies as real records — creatable, listable, with their master data enterable — on top of Filament's multi-tenancy, so the company lives in the URL and the next wave arrives at a tenancy boundary that already exists.

**Architecture:** One `Company` model with a UUID v7 key and a stable slug, reached by a user through a `company_user` join table. The existing Filament panel gains `->tenant(Company::class, slugAttribute: 'slug')`, which puts the slug in the path, supplies the switcher, and routes company-scoped screens under `/admin/{company}/…`. Creation happens on a tenant-registration page; editing happens on a tenant-profile page; a single unscoped resource lists companies.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5.8, PostgreSQL 17, Pest 4, Larastan level 8, Rector, Pint. Everything runs in Docker.

**Spec:** `docs/superpowers/specs/2026-09-24-companies-and-tenancy-design.md` (this plan argues from it; read both). Its parent is `docs/superpowers/specs/2026-09-23-invoice-system-design.md`.

## Global Constraints

Every task's requirements implicitly include all of these.

- **Every command runs in the container.** Nothing is installed on the host: `docker compose run --rm app <command>`.
- **PostgreSQL only, never SQLite, including in tests.** `phpunit.xml` already sets `DB_CONNECTION=pgsql` and `DB_DATABASE=invoice_test`.
- **UUID primary keys on every model**, version 7 via Laravel's `HasUuids`. The UUID *is* the key.
- **Larastan stays at level 8.** Fix findings, or baseline them with a stated reason. Paths analysed: `app`, `config`, `database`, `routes`, `tests`.
- **Rector runs before Pint**, and covers `app/` and `tests/` only. The pair only settles in that order.
- **Identifiers are English.** German appears only in user-facing labels, which live in `lang/de/company.php`, and in legal designations printed verbatim.
- **Row actions live in one `ActionGroup`** (vertical-ellipsis dropdown). Never a row of buttons.
- **Show less rather than more.** No speculative filter, column, widget or badge.
- **Every test must be able to fail.** Before trusting one, answer: what would make this fail? The repo has produced four tests that passed while the thing they guarded was broken.
- **No Node.js.** Filament serves pre-built assets.
- `declare(strict_types=1);` at the top of every PHP file in `app/` and `tests/`, matching the existing files. Laravel's own migration and factory files in this repo do not carry it — follow each directory's existing convention.

## Review Focus

Five things the spec implies that would otherwise reach a person unexercised. Each has a test pinned to the task that owns the code.

1. **Filament generates tenant URLs from `getRouteKey()`, not from the panel's `slugAttribute`.** Without `Company::getRouteKeyName()` returning `'slug'`, the switcher and every "Open" link emit `/admin/{uuid}`, which then fails to resolve because identification queries the `slug` column — a panel that looks configured and is entirely broken. Pinned to Task 1 (unit) and Task 5 (end to end).
2. **An IBAN typed off a bank statement carries spaces and may be lower case.** Rejecting `DE89 3704 0044 0532 0130 00` as invalid would be a bug in the validator, not in the input. Pinned to Task 4.
3. **German company names and addresses carry umlauts.** This repo has already shipped a PDF where every German character was mojibake while the suite stayed green, so a round-trip through form, database and list view is worth asserting on real input. Pinned to Task 6.
4. **A name with nothing sluggable, and a collision with an already-suffixed slug.** `Str::slug('&&&')` is the empty string, which would produce an unroutable company and then a unique-constraint violation on the second one; and a third "Acme GmbH" must not collide with an existing `acme-gmbh-2`. Pinned to Task 1.
5. **Archiving the company you are currently inside.** Filament still has that tenant set for the rest of the request while it has just left `getTenants()`. Pinned to Task 7.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `app/Enums/LegalForm.php` | The three legal forms, and the one predicate both forms and the document ask: is this company in the Handelsregister? |
| `app/Enums/VatScheme.php` | Standard vs small-business VAT scheme (§19 UStG) |
| `app/Models/Company.php` | The company: slug generation, route key, archiving, relationships |
| `app/Exceptions/CannotArchiveLastCompany.php` | Refusal of the archive that would leave no active company |
| `app/Rules/Iban.php` | IBAN format plus mod-97 checksum |
| `app/Filament/Pages/Tenancy/RegisterCompany.php` | The only company-creation surface |
| `app/Filament/Pages/Tenancy/CompanySettings.php` | The only company-editing surface |
| `app/Filament/Resources/Companies/CompanyResource.php` | Unscoped resource declaration |
| `app/Filament/Resources/Companies/Pages/ListCompanies.php` | Its single page |
| `app/Filament/Resources/Companies/Tables/CompaniesTable.php` | Columns and the row action group |
| `database/migrations/2026_09_24_100000_create_companies_table.php` | |
| `database/migrations/2026_09_24_100100_create_company_user_table.php` | |
| `database/factories/CompanyFactory.php` | |
| `lang/de/company.php` | Every German string this wave puts on screen |
| `tests/Feature/CompanyTest.php` | Model: keys, slugs, route key, archiving |
| `tests/Feature/CompanyTenancyTest.php` | The boundary: who reaches which company by which URL |
| `tests/Feature/IbanRuleTest.php` | The checksum |
| `tests/Feature/CompanySettingsTest.php` | The settings form and its conditional validation |
| `tests/Feature/CompanyListTest.php` | The list page and its row actions |

**Modified:**

| File | Change |
|---|---|
| `app/Models/User.php` | `implements HasTenants`; `companies()`, `getTenants()`, `canAccessTenant()` |
| `app/Providers/Filament/AdminPanelProvider.php` | `tenant()`, `tenantRegistration()`, `tenantProfile()`, `tenantMenuItems()` |
| `tests/Feature/PanelTest.php` | The dashboard test now needs a company; add the no-company redirect |
| `CLAUDE.md`, `README.md`, the system design spec, `.ai/guidelines/`, Outline | §10 of the spec |

**Note on what is deliberately absent:** there is no `Company` query scope for "active". The predicate has exactly two call sites (`User::getTenants()` and `Company::canBeArchived()`), and a `#[Scope]` method invites a Larastan level-8 finding about a dynamic builder call for no gain. Write `whereNull('archived_at')` at both sites. Revisit at the third.

There is also no `CompanyPolicy`. Filament's `authorize()` helper returns `Response::allow()` when no policy exists and strict authorization mode is off, which is the case here — so the registration and settings pages are reachable without one. A policy is where archive permissions would go if roles ever land.

---

## Task 1: The Company model, its enums, and slug generation

**Files:**
- Create: `app/Enums/LegalForm.php`
- Create: `app/Enums/VatScheme.php`
- Create: `app/Models/Company.php`
- Create: `database/migrations/2026_09_24_100000_create_companies_table.php`
- Create: `database/factories/CompanyFactory.php`
- Create: `lang/de/company.php`
- Test: `tests/Feature/CompanyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `App\Enums\LegalForm` — cases `GmbH`, `UG`, `SoleProprietorship`; backed by `'gmbh'`, `'ug'`, `'sole_proprietorship'`; implements `Filament\Support\Contracts\HasLabel`; `isRegistered(): bool`; `getLabel(): string`
  - `App\Enums\VatScheme` — cases `Standard`, `SmallBusiness`; backed by `'standard'`, `'small_business'`; implements `HasLabel`; `getLabel(): string`
  - `App\Models\Company` — `uniqueSlugFrom(string $name): string` (static), `getRouteKeyName(): string`, `isArchived(): bool`, `users(): BelongsToMany`; casts `legal_form` to `LegalForm`, `vat_scheme` to `VatScheme`, `archived_at` to `datetime`
  - `Database\Factories\CompanyFactory` — states `soleProprietorship()`, `archived()`
  - `lang/de/company.php` — keys `legal_form.*`, `vat_scheme.*`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\LegalForm;
use App\Enums\VatScheme;
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
    $company = Company::create([
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
```

- [ ] **Step 2: Run the test to verify it fails**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTest.php
```

Expected: every test errors with `Class "App\Models\Company" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_24_100000_create_companies_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_form');
            $table->string('vat_scheme');

            // Nullable because a company is created with a name and a legal
            // form only, then completed on its settings page. The settings
            // form is where these become required.
            $table->string('street')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('city')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('vat_id')->nullable();
            $table->string('register_court')->nullable();
            $table->string('register_number')->nullable();
            $table->string('managing_directors')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('iban')->nullable();
            $table->string('bic')->nullable();

            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
```

- [ ] **Step 4: Write the enums**

Create `app/Enums/LegalForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LegalForm: string implements HasLabel
{
    case GmbH = 'gmbh';
    case UG = 'ug';
    case SoleProprietorship = 'sole_proprietorship';

    /**
     * Whether this form is entered in the Handelsregister.
     *
     * This is the one question both the settings form and, later, the invoice
     * footer ask of a legal form: a registered company must state its court,
     * its HRB number and its Geschäftsführer, and a sole proprietorship has
     * none of the three. Asking it here keeps the answer in one place instead
     * of a match expression at every call site.
     */
    public function isRegistered(): bool
    {
        return match ($this) {
            self::GmbH, self::UG => true,
            self::SoleProprietorship => false,
        };
    }

    public function getLabel(): string
    {
        return __("company.legal_form.{$this->value}");
    }
}
```

Create `app/Enums/VatScheme.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which VAT scheme a company invoices under.
 *
 * `SmallBusiness` is the election under §19 UStG: no VAT is charged and the
 * invoice carries a note saying so. It carries no behaviour in this wave — it
 * is here because it is master data entered now, and because it is the reason
 * a VAT ID cannot be required of every company.
 */
enum VatScheme: string implements HasLabel
{
    case Standard = 'standard';
    case SmallBusiness = 'small_business';

    public function getLabel(): string
    {
        return __("company.vat_scheme.{$this->value}");
    }
}
```

- [ ] **Step 5: Write the German strings**

Create `lang/de/company.php`:

```php
<?php

declare(strict_types=1);

return [
    'legal_form' => [
        'gmbh' => 'GmbH',
        'ug' => 'UG (haftungsbeschränkt)',
        'sole_proprietorship' => 'Einzelunternehmen',
    ],

    'vat_scheme' => [
        'standard' => 'Regelbesteuerung',
        'small_business' => 'Kleinunternehmer (§19 UStG)',
    ],
];
```

- [ ] **Step 6: Write the model**

Create `app/Models/Company.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'legal_form',
    'vat_scheme',
    'street',
    'postal_code',
    'city',
    'tax_number',
    'vat_id',
    'register_court',
    'register_number',
    'managing_directors',
    'bank_name',
    'iban',
    'bic',
])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory, HasUuids;

    /**
     * The slug is never mass-assigned and `archived_at` is written only through
     * the archiving methods, so neither appears in the fillable list above.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'vat_scheme' => 'standard',
    ];

    /**
     * Filament builds tenant URLs with `route(..., ['tenant' => $company])`,
     * which calls `getRouteKey()`. The panel identifies a tenant by querying
     * the `slug` column, so generation has to agree with resolution or every
     * link in the switcher points at a URL that 404s.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public static function uniqueSlugFrom(string $name): string
    {
        $base = Str::slug($name);

        // A name of nothing but punctuation slugs to the empty string, which
        // would be an unreachable URL and then a unique-index violation on the
        // next one.
        if ($base === '') {
            $base = 'company';
        }

        $slug = $base;
        $suffix = 2;

        // Counts past suffixes already in use rather than assuming "-2" is
        // free. The unique index is what guarantees correctness under a race;
        // this loop is what keeps the common case from hitting it.
        while (self::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    protected static function booted(): void
    {
        // Set on create and never again: the slug is a stable public
        // identifier, and a rename must not break a bookmarked URL.
        static::creating(function (Company $company): void {
            $company->slug ??= self::uniqueSlugFrom((string) $company->name);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_form' => LegalForm::class,
            'vat_scheme' => VatScheme::class,
            'archived_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 7: Write the factory**

Create `database/factories/CompanyFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A complete GmbH, so a test that cares about one field does not have to
     * supply the other thirteen. The slug is deliberately absent: it is the
     * model's job, and a factory that set it would hide that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' GmbH',
            'legal_form' => LegalForm::GmbH,
            'vat_scheme' => VatScheme::Standard,
            'street' => fake()->streetAddress(),
            'postal_code' => fake()->numerify('#####'),
            'city' => fake()->city(),
            'tax_number' => fake()->numerify('###/###/#####'),
            'vat_id' => 'DE'.fake()->numerify('#########'),
            'register_court' => 'Amtsgericht '.fake()->city(),
            'register_number' => 'HRB '.fake()->numerify('#####'),
            'managing_directors' => fake()->name(),
            'bank_name' => fake()->company(),
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
        ];
    }

    /**
     * A sole proprietorship: no Handelsregister entry, no Geschäftsführer.
     */
    public function soleProprietorship(): static
    {
        return $this->state(fn (array $attributes): array => [
            'legal_form' => LegalForm::SoleProprietorship,
            'register_court' => null,
            'register_number' => null,
            'managing_directors' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now(),
        ]);
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTest.php
```

Expected: PASS, 12 tests.

If `starts a new company on the standard vat scheme` fails with a not-null violation on `legal_form`, the `#[Fillable]` attribute is missing that key.

- [ ] **Step 9: Run the toolchain**

```sh
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

Expected: Rector and Pint may rewrite files; PHPStan reports no errors; the whole suite passes. Re-run Pest after any Rector rewrite.

- [ ] **Step 10: Commit**

```sh
git add app/Enums app/Models/Company.php database/migrations database/factories lang tests/Feature/CompanyTest.php
git commit -m "feat: add the company model with a stable slug

The slug is generated once, on create, and is what Filament will route
tenants by — so getRouteKeyName returns it rather than the UUID, or every
switcher link would point at a URL the panel cannot resolve.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: The user–company join, and the tenancy contract

**Files:**
- Create: `database/migrations/2026_09_24_100100_create_company_user_table.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/CompanyTenancyTest.php`

**Interfaces:**
- Consumes: `App\Models\Company` (Task 1).
- Produces:
  - `App\Models\User::companies(): BelongsToMany` — the join
  - `App\Models\User::getTenants(Panel $panel): Collection` — the user's **non-archived** companies, ordered by name
  - `App\Models\User::canAccessTenant(Model $tenant): bool` — membership of the join table, **archived included**

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanyTenancyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;

it('carries uuids through both company_user foreign keys', function (): void {
    // The half that silently rots: companies.id can be a uuid while
    // company_user.company_id stays bigint, and nothing complains until an
    // attach is attempted.
    $columns = DB::select(
        'select column_name, data_type from information_schema.columns
         where table_name = ? and column_name in (?, ?)',
        ['company_user', 'company_id', 'user_id']
    );

    expect($columns)->toHaveCount(2);

    foreach ($columns as $column) {
        expect($column->data_type)->toBe('uuid');
    }
});

it('offers the user only their own non-archived companies as tenants', function (): void {
    $user = User::factory()->create();
    $mine = Company::factory()->create(['name' => 'Mine GmbH']);
    $archived = Company::factory()->archived()->create(['name' => 'Archived GmbH']);
    $someoneElses = Company::factory()->create(['name' => 'Theirs GmbH']);

    $user->companies()->attach([$mine->getKey(), $archived->getKey()]);

    $tenants = $user->getTenants(Filament::getPanel('admin'));

    // Three assertions because each rules out a different wrong
    // implementation: returning everything, returning archived companies, and
    // returning nothing at all.
    expect($tenants->pluck('name')->all())->toBe(['Mine GmbH'])
        ->and($tenants->contains($archived))->toBeFalse()
        ->and($tenants->contains($someoneElses))->toBeFalse();
});

it('still lets the user into an archived company they are linked to', function (): void {
    // An archived company leaves the switcher but keeps its URL, because its
    // historical documents have to stay readable.
    $user = User::factory()->create();
    $archived = Company::factory()->archived()->create();

    $user->companies()->attach($archived);

    expect($user->canAccessTenant($archived))->toBeTrue();
});

it('keeps the user out of a company they are not linked to', function (): void {
    // This is the tenancy boundary. Filament calls exactly this method before
    // setting the tenant for the request.
    $user = User::factory()->create();
    $theirs = Company::factory()->create();

    expect($user->canAccessTenant($theirs))->toBeFalse();
});

it('orders tenants by name, so the default company is deterministic', function (): void {
    // Filament picks the user's default tenant off the front of this list and
    // redirects /admin to it. Unordered, which company you land on depends on
    // insertion order.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Zeta GmbH']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));

    expect($user->getTenants(Filament::getPanel('admin'))->pluck('name')->all())
        ->toBe(['Alpha GmbH', 'Zeta GmbH']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTenancyTest.php
```

Expected: FAIL — the `company_user` table does not exist, and `Call to undefined method App\Models\User::companies()`.

- [ ] **Step 3: Write the pivot migration**

Create `database/migrations/2026_09_24_100100_create_company_user_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_user', function (Blueprint $table) {
            $table->foreignUuid('company_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Composite key rather than a UUID of its own: this pivot carries
            // no model and no identifier that could reach a URL. It also gives
            // the uniqueness constraint for free.
            $table->primary(['company_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_user');
    }
};
```

- [ ] **Step 4: Implement the tenancy contract on User**

Modify `app/Models/User.php`. Add to the imports:

```php
use Filament\Models\Contracts\HasTenants;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;
```

Change the class declaration:

```php
class User extends Authenticatable implements FilamentUser, HasTenants
```

And add these three methods to the class body, above `canAccessPanel()`:

```php
    /**
     * @return BelongsToMany<Company, $this>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class);
    }

    /**
     * The companies offered in the switcher.
     *
     * Archived companies are excluded here but still pass
     * `canAccessTenant()`: they are out of use, so they leave the switcher,
     * while their URL keeps working because their documents stay readable.
     *
     * Ordered by name so that the company Filament picks as the user's default
     * — and therefore where `/admin` lands — does not depend on insertion
     * order.
     *
     * @return Collection<int, Company>
     */
    public function getTenants(Panel $panel): Collection
    {
        return $this->companies()
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get();
    }

    /**
     * The tenancy boundary. Filament calls this before setting the tenant for
     * a request and aborts with a 404 when it returns false.
     */
    public function canAccessTenant(Model $tenant): bool
    {
        return $this->companies()->whereKey($tenant->getKey())->exists();
    }
```

`Panel` is already imported in this file. Add `use App\Models\Company;`? No — `Company` is in the same namespace, so no import is needed.

- [ ] **Step 5: Run the test to verify it passes**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTenancyTest.php
```

Expected: PASS, 5 tests.

- [ ] **Step 6: Run the toolchain**

```sh
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

Expected: no PHPStan errors, whole suite passes. If Larastan objects to the `Collection` return type narrowing the interface's `array | Collection`, the fix is the `@return Collection<int, Company>` docblock above, not a baseline entry.

- [ ] **Step 7: Commit**

```sh
git add database/migrations app/Models/User.php tests/Feature/CompanyTenancyTest.php
git commit -m "feat: link users to companies and declare the tenancy boundary

getTenants hides archived companies from the switcher; canAccessTenant
still admits them, so an archived company's documents stay reachable by
URL. That asymmetry is the point.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Archiving, and the refusal to archive the last company

**Files:**
- Create: `app/Exceptions/CannotArchiveLastCompany.php`
- Modify: `app/Models/Company.php`
- Test: `tests/Feature/CompanyTest.php` (append)

**Interfaces:**
- Consumes: `App\Models\Company` (Task 1).
- Produces:
  - `App\Models\Company::canBeArchived(): bool`
  - `App\Models\Company::archive(): void` — throws `CannotArchiveLastCompany`
  - `App\Models\Company::unarchive(): void`
  - `App\Exceptions\CannotArchiveLastCompany extends RuntimeException`

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CompanyTest.php`, and add `use App\Exceptions\CannotArchiveLastCompany;` to its imports:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTest.php
```

Expected: FAIL with `Call to undefined method App\Models\Company::archive()`.

- [ ] **Step 3: Write the exception**

Create `app/Exceptions/CannotArchiveLastCompany.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Company;
use RuntimeException;

class CannotArchiveLastCompany extends RuntimeException
{
    public function __construct(public readonly Company $company)
    {
        parent::__construct(
            "Refusing to archive [{$company->name}]: it is the last active company."
        );
    }
}
```

- [ ] **Step 4: Implement archiving on the model**

Add to `app/Models/Company.php`, after `isArchived()`. Add `use App\Exceptions\CannotArchiveLastCompany;` to its imports.

```php
    /**
     * Whether this company may be archived.
     *
     * An active company can be archived only while another active one remains.
     * The last one is refused because archiving it strands the owner: Filament
     * redirects a user with no tenants to registration, and every screen that
     * could restore a company sits behind the tenant prefix.
     *
     * The count is global rather than per user. With one user those are the
     * same thing, and making it per user would be the only place in this wave
     * that pretends there are several.
     */
    public function canBeArchived(): bool
    {
        if ($this->isArchived()) {
            return false;
        }

        return self::query()->whereNull('archived_at')->count() > 1;
    }

    public function archive(): void
    {
        if (! $this->canBeArchived()) {
            throw new CannotArchiveLastCompany($this);
        }

        // forceFill because archived_at is deliberately not fillable: it is
        // state, changed through these two methods and nowhere else.
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }
```

- [ ] **Step 5: Run the test to verify it passes**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTest.php
```

Expected: PASS, 17 tests.

- [ ] **Step 6: Run the toolchain**

```sh
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

Expected: clean.

- [ ] **Step 7: Commit**

```sh
git add app/Exceptions app/Models/Company.php tests/Feature/CompanyTest.php
git commit -m "feat: archive companies, and refuse to archive the last one

Nothing referenced by an issued document is ever deleted, so companies
are archived. Archiving the last active one is refused: it leaves no
screen from which to restore it.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: The IBAN rule

**Files:**
- Create: `app/Rules/Iban.php`
- Modify: `app/Models/Company.php` (normalising mutator)
- Modify: `lang/de/company.php` (the failure message)
- Test: `tests/Feature/IbanRuleTest.php`

**Interfaces:**
- Consumes: `App\Models\Company` (Task 1).
- Produces:
  - `App\Rules\Iban implements Illuminate\Contracts\Validation\ValidationRule` — `validate(string $attribute, mixed $value, Closure $fail): void`
  - `App\Models\Company::iban` — stored uppercase with whitespace stripped
  - `lang/de/company.php` key `errors.iban`

The test lives in `tests/Feature/` rather than `tests/Unit/` because `tests/Pest.php` binds `Tests\TestCase` to `Feature` only, and both `Validator` and `__()` need the application container.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/IbanRuleTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Rules\Iban;
use Illuminate\Support\Facades\Validator;

/**
 * @param  mixed  $value
 */
function ibanPasses(mixed $value): bool
{
    return Validator::make(['iban' => $value], ['iban' => new Iban])->passes();
}

it('accepts a valid german iban', function (): void {
    expect(ibanPasses('DE89370400440532013000'))->toBeTrue();
});

it('accepts an iban as it is printed on a statement', function (): void {
    // Grouped in fours and sometimes lower case. Rejecting this would be a bug
    // in the validator, not in the input.
    expect(ibanPasses('DE89 3704 0044 0532 0130 00'))->toBeTrue()
        ->and(ibanPasses('de89370400440532013000'))->toBeTrue();
});

it('rejects an iban with two digits transposed', function (): void {
    // DE98… is DE89… with the check digits swapped. mod-97 detects every
    // transposition of adjacent digits, which is the realistic typing error,
    // and is the whole reason the checksum is here. A rule that only checked
    // the length and the country prefix would pass this.
    expect(ibanPasses('DE98370400440532013000'))->toBeFalse()
        ->and(ibanPasses('DE89370400440532010300'))->toBeFalse();
});

it('rejects things that are not ibans', function (): void {
    expect(ibanPasses('not an iban'))->toBeFalse()
        ->and(ibanPasses('DE89'))->toBeFalse()
        ->and(ibanPasses(''))->toBeFalse()
        ->and(ibanPasses(12345))->toBeFalse();
});

it('accepts a valid iban from another country', function (): void {
    // The rule is mod-97 plus a length range, not a table of German lengths.
    expect(ibanPasses('AT611904300234573201'))->toBeTrue();
});

it('reports a german message rather than a bare translation key', function (): void {
    $validator = Validator::make(['iban' => 'nope'], ['iban' => new Iban]);

    expect($validator->passes())->toBeFalse()
        ->and($validator->errors()->first('iban'))->not->toContain('company.errors');
});

it('stores the iban normalised, whatever was typed', function (): void {
    // Otherwise the same account is stored three ways and no two companies
    // compare equal.
    $company = Company::factory()->create(['iban' => 'de89 3704 0044 0532 0130 00']);

    expect($company->fresh()?->iban)->toBe('DE89370400440532013000');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/IbanRuleTest.php
```

Expected: FAIL with `Class "App\Rules\Iban" not found`.

- [ ] **Step 3: Write the rule**

Create `app/Rules/Iban.php`:

```php
<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an IBAN by format and by its mod-97 checksum.
 *
 * The checksum is the point. The realistic error is a transposed pair of
 * digits in an account number typed off a bank statement, and mod-97 catches
 * every one of those. A length-and-prefix check would not.
 *
 * Country-specific lengths are deliberately not encoded: the checksum plus a
 * length range rejects everything worth rejecting without a table that goes
 * stale.
 */
class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('company.errors.iban');

            return;
        }

        $iban = mb_strtoupper((string) preg_replace('/\s+/', '', $value));

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            $fail('company.errors.iban');

            return;
        }

        if ($this->remainderOf($iban) !== 1) {
            $fail('company.errors.iban');
        }
    }

    /**
     * The mod-97 remainder, computed in chunks.
     *
     * An IBAN rearranged into digits is far wider than an integer, so it is
     * folded seven digits at a time — which keeps this free of bcmath and of
     * any assumption about the container's extensions.
     */
    private function remainderOf(string $iban): int
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $numeric = '';

        foreach (str_split($rearranged) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord($character) - 55)
                : $character;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}
```

- [ ] **Step 4: Add the message and the normalising mutator**

In `lang/de/company.php`, add a top-level key:

```php
    'errors' => [
        'iban' => 'Diese IBAN ist ungültig.',
    ],
```

In `app/Models/Company.php`, add `use Illuminate\Database\Eloquent\Casts\Attribute;` to the imports and this method after `isArchived()`:

```php
    /**
     * Stored without spaces and upper-cased, so the same account is one value
     * however it was typed.
     *
     * @return Attribute<string|null, string|null>
     */
    protected function iban(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): ?string => $value === null
                ? null
                : mb_strtoupper((string) preg_replace('/\s+/', '', $value)),
        );
    }
```

- [ ] **Step 5: Run the test to verify it passes**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/IbanRuleTest.php
```

Expected: PASS, 7 tests.

- [ ] **Step 6: Run the toolchain**

```sh
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

Expected: clean.

- [ ] **Step 7: Commit**

```sh
git add app/Rules app/Models/Company.php lang tests/Feature/IbanRuleTest.php
git commit -m "feat: validate IBANs by their mod-97 checksum

Accepts an IBAN as printed on a statement — grouped in fours, any case —
and normalises it on the way in. The checksum is what catches a
transposed pair of digits.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: Panel tenancy, and the company-registration page

**Files:**
- Create: `app/Filament/Pages/Tenancy/RegisterCompany.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `lang/de/company.php`
- Modify: `tests/Feature/PanelTest.php`
- Test: `tests/Feature/CompanyTenancyTest.php` (append)

**Interfaces:**
- Consumes: `App\Models\Company` (Task 1), `User::getTenants()` / `canAccessTenant()` (Task 2).
- Produces:
  - `App\Filament\Pages\Tenancy\RegisterCompany` — Filament's tenant-registration page at `/admin/new`; creates the company, attaches the current user, redirects to the company's settings
  - The panel is tenant-aware: company-scoped routes are `/admin/{company}/…`

This task references `App\Filament\Pages\Tenancy\CompanySettings`, which Task 6 creates. Create Task 6's file first as a stub if you are implementing strictly in order:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;

class CompanySettings extends EditTenantProfile
{
    protected static ?string $slug = 'settings';

    public static function getLabel(): string
    {
        return __('company.settings.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }
}
```

Task 6 fills in that form. The stub is here because the panel cannot register `tenantProfile()` against a class that does not exist, and because Filament's own `/admin/{company}/settings` route is what Task 5's tests assert against.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/CompanyTenancyTest.php`:

```php
it('sends a user with no company to company registration', function (): void {
    // This is how the first company gets created. There is no seeder and no
    // signup, so if this redirect does not happen the application is
    // unusable from a clean database.
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertRedirect('/admin/new');
});

it('lands a user on their company, addressed by slug', function (): void {
    // Exercises Filament's URL generation end to end: getUrl() builds this
    // with route(..., ['tenant' => $company]), which calls getRouteKey(). If
    // the model did not route by slug this would redirect to /admin/{uuid},
    // which the tenant middleware then cannot resolve.
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user->companies()->attach($company);

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect('/admin/acme-gmbh');
});

it('serves the company registration page', function (): void {
    $this->actingAs(User::factory()->create())
        ->get('/admin/new')
        ->assertOk();
});

it('creates a company, links the user to it, and lands on its settings', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(RegisterCompany::class)
        ->fillForm([
            'name' => 'Ölmühle Müller GmbH',
            'legal_form' => LegalForm::SoleProprietorship->value,
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect('/admin/olmuhle-muller-gmbh/settings');

    $company = Company::query()->where('slug', 'olmuhle-muller-gmbh')->sole();

    // The attach is the part that is easy to forget, and without it the user
    // creates a company they immediately cannot reach.
    expect($company->name)->toBe('Ölmühle Müller GmbH')
        ->and($company->legal_form)->toBe(LegalForm::SoleProprietorship)
        ->and($user->fresh()?->companies)->toHaveCount(1);
});

it('refuses a company registration with no name', function (): void {
    Livewire::actingAs(User::factory()->create())
        ->test(RegisterCompany::class)
        ->fillForm(['name' => '', 'legal_form' => LegalForm::GmbH->value])
        ->call('register')
        ->assertHasFormErrors(['name']);
});

it('hides one company behind a 404 from a user who is not linked to it', function (): void {
    // The characteristic failure of a multi-company invoicing system is one
    // company's data appearing under another's URL. Filament aborts with 404
    // rather than 403, so that an outsider cannot even confirm the company
    // exists.
    $user = User::factory()->create();
    $mine = Company::factory()->create(['name' => 'Mine GmbH']);
    $theirs = Company::factory()->create(['name' => 'Theirs GmbH']);
    $user->companies()->attach($mine);

    $this->actingAs($user)
        ->get('/admin/theirs-gmbh')
        ->assertNotFound();
});
```

Add these imports at the top of the file:

```php
use App\Enums\LegalForm;
use App\Filament\Pages\Tenancy\RegisterCompany;
use Livewire\Livewire;
```

- [ ] **Step 2: Run the test to verify it fails**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTenancyTest.php
```

Expected: FAIL — `/admin` still serves the dashboard rather than redirecting, and `App\Filament\Pages\Tenancy\RegisterCompany` does not exist.

- [ ] **Step 3: Write the registration page**

Create `app/Filament/Pages/Tenancy/RegisterCompany.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Enums\LegalForm;
use App\Models\Company;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * The only place a company is created.
 *
 * It asks for the two things a company needs in order to exist and be
 * routable, and then lands the user on its settings page for the rest. Being
 * Filament's tenant-registration page, it is also where a user with no
 * companies is sent — which is how the first company comes into being, with no
 * seeder and no signup.
 */
class RegisterCompany extends RegisterTenant
{
    public static function getLabel(): string
    {
        return __('company.register.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('company.fields.name'))
                ->helperText(__('company.register.name_help'))
                ->required()
                ->maxLength(255),
            Select::make('legal_form')
                ->label(__('company.fields.legal_form'))
                ->options(LegalForm::class)
                ->required(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(array $data): Company
    {
        $company = Company::create($data);

        // Without this the user has created a company that fails
        // canAccessTenant() and is therefore unreachable.
        $company->users()->attach(Auth::id());

        return $company;
    }

    protected function getRedirectUrl(): ?string
    {
        // Filament would otherwise send the user to the company's dashboard.
        // A freshly created company has an incomplete identity block, so the
        // settings page is the only useful next screen.
        return route(CompanySettings::getRouteName(), ['tenant' => $this->tenant]);
    }
}
```

- [ ] **Step 4: Add the German strings**

In `lang/de/company.php`, add:

```php
    'register' => [
        'title' => 'Neue Firma',
        'name_help' => 'Der vollständige rechtliche Name. Er erscheint auf der Rechnung.',
    ],

    'settings' => [
        'title' => 'Firmendaten',
    ],

    'fields' => [
        'name' => 'Name',
        'legal_form' => 'Rechtsform',
    ],
```

- [ ] **Step 5: Turn tenancy on in the panel**

Modify `app/Providers/Filament/AdminPanelProvider.php`. Add to the imports:

```php
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Models\Company;
```

Add these three calls to the `panel()` chain, directly after `->login()`:

```php
            // The current company lives in the URL, not only in the session:
            // every company-scoped screen sits under /admin/{company}. Held in
            // session alone, one URL would show different companies to the
            // same person and a second tab would fight the first.
            ->tenant(Company::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterCompany::class)
            ->tenantProfile(CompanySettings::class)
```

No middleware change is needed: Filament adds `IdentifyTenant` to the tenant route group itself.

- [ ] **Step 6: Update the existing panel test**

Modify `tests/Feature/PanelTest.php`. Replace the test named `shows the dashboard to an authenticated user` with:

```php
it('shows the dashboard of the users company', function (): void {
    /** @var TestCase $this */
    // Tenancy means the dashboard now lives under the company's slug, and a
    // user with no company is redirected to registration instead — so this
    // test needs a company to have anything to show.
    //
    // Asserting on the user's name rather than on the word "Dashboard": the
    // app runs with locale=de and Filament ships German translations, so any
    // chrome string is a translation change away from breaking. The name is
    // data, and its presence proves the panel chrome rendered.
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user->companies()->attach($company);

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee($user->name);
});
```

Add `use App\Models\Company;` to that file's imports.

- [ ] **Step 7: Run the tests to verify they pass**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTenancyTest.php tests/Feature/PanelTest.php
```

Expected: PASS, 11 + 4 tests.

If `lands a user on their company, addressed by slug` redirects to `/admin/{uuid}`, `Company::getRouteKeyName()` from Task 1 is missing or wrong.

- [ ] **Step 8: Run the toolchain**

```sh
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

Expected: clean, whole suite green.

- [ ] **Step 9: Commit**

```sh
git add app/Filament app/Providers/Filament/AdminPanelProvider.php lang tests/Feature
git commit -m "feat: put the company in the URL

The panel becomes tenant-aware, so company-scoped screens live under
/admin/{company}. Registration is the single creation path and doubles as
the bootstrap: a user with no company is sent there.

A company a user is not linked to answers 404, not 403 — an outsider
should not be able to confirm it exists.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: The company settings page

**Files:**
- Modify: `app/Filament/Pages/Tenancy/CompanySettings.php` (fill in the Task 5 stub)
- Modify: `lang/de/company.php`
- Test: `tests/Feature/CompanySettingsTest.php`

**Interfaces:**
- Consumes: `Company`, `LegalForm`, `VatScheme` (Task 1); `Iban` (Task 4); the tenant-aware panel (Task 5).
- Produces: `CompanySettings` at `/admin/{company}/settings`, the only surface that edits a company.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanySettingsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\LegalForm;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

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
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    $this->actingAs(userOf([$company]))
        ->get('/admin/acme-gmbh/settings')
        ->assertOk()
        ->assertSee('Acme GmbH');
});

it('shows the company named in the url, not the one in the session', function (): void {
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
    $mine = Company::factory()->create(['name' => 'Mine GmbH']);
    $theirs = Company::factory()->create(['name' => 'Theirs GmbH']);

    $this->actingAs(userOf([$mine]))
        ->get('/admin/theirs-gmbh/settings')
        ->assertNotFound();
});

it('round-trips umlauts through the settings form', function (): void {
    // This repository has already shipped a PDF in which every German
    // character was mojibake while the suite stayed green. German company
    // names and streets are the normal case here, not an edge one.
    $company = Company::factory()->create(['name' => 'Ölmühle Müller GmbH']);
    $user = userOf([$company]);

    Filament::setTenant($company);

    Livewire::actingAs($user)
        ->test(CompanySettings::class)
        ->fillForm([
            'street' => 'Grünstraße 7',
            'city' => 'Rosenheim',
            'postal_code' => '83022',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->street)->toBe('Grünstraße 7');

    $this->actingAs($user)
        ->get('/admin/olmuhle-muller-gmbh/settings')
        ->assertOk()
        ->assertSee('Grünstraße 7');
});

it('requires the register fields of a company that is in the handelsregister', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    Filament::setTenant($company);

    Livewire::actingAs($user)
        ->test(CompanySettings::class)
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

    Filament::setTenant($company);

    Livewire::actingAs($user)
        ->test(CompanySettings::class)
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

    Filament::setTenant($company);

    Livewire::actingAs($user)
        ->test(CompanySettings::class)
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

    Filament::setTenant($company);

    Livewire::actingAs($user)
        ->test(CompanySettings::class)
        ->fillForm(['tax_number' => '', 'vat_id' => ''])
        ->call('save')
        ->assertHasFormErrors(['tax_number', 'vat_id']);
});

it('accepts a company with only a steuernummer', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    Filament::setTenant($company);

    Livewire::actingAs($user)
        ->test(CompanySettings::class)
        ->fillForm(['tax_number' => '143/815/08151', 'vat_id' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->vat_id)->toBeNull();
});

it('rejects an invalid iban on the settings form', function (): void {
    $company = Company::factory()->create();
    $user = userOf([$company]);

    Filament::setTenant($company);

    Livewire::actingAs($user)
        ->test(CompanySettings::class)
        ->fillForm(['iban' => 'DE98370400440532013000'])
        ->call('save')
        ->assertHasFormErrors(['iban']);
});

it('never lets the slug be edited', function (): void {
    // The slug is a stable public identifier. If the form could write it, a
    // rename would break every bookmarked URL.
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = userOf([$company]);

    Filament::setTenant($company);

    Livewire::actingAs($user)
        ->test(CompanySettings::class)
        ->fillForm(['slug' => 'something-else'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($company->fresh()?->slug)->toBe('acme-gmbh');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanySettingsTest.php
```

Expected: FAIL — the stub form has no components, so `fillForm()` finds no fields and the validation tests report no errors.

**If a Livewire page test fails with a missing current panel**, call
`Filament::setCurrentPanel('admin')` before `Filament::setTenant(...)`. The
admin panel is registered with `->default()`, so it normally resolves on its
own; the explicit call is the fix if it does not.

- [ ] **Step 3: Write the settings form**

Replace `app/Filament/Pages/Tenancy/CompanySettings.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Rules\Iban;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The only surface that edits a company.
 *
 * There is deliberately no edit page on the companies resource: two surfaces
 * onto one record drift apart, and the second exists only to duplicate the
 * first.
 *
 * This is also where the identity block is required. A company created through
 * registration has a name and a legal form and nothing else, so it is
 * incomplete until this page has been saved once. Issuing a document from an
 * incomplete company is refused in a later wave, not here.
 */
class CompanySettings extends EditTenantProfile
{
    /**
     * Gives the page the URL the design calls for: /admin/{company}/settings
     * rather than Filament's default /profile.
     */
    protected static ?string $slug = 'settings';

    public static function getLabel(): string
    {
        return __('company.settings.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('company.sections.identity'))->schema([
                TextInput::make('name')
                    ->label(__('company.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('company.fields.slug'))
                    ->helperText(__('company.fields.slug_help'))
                    // Shown because it is the company's address on the web, and
                    // never written: dehydrated(false) keeps it out of the
                    // saved data even if the input is tampered with.
                    ->disabled()
                    ->dehydrated(false),
                Select::make('legal_form')
                    ->label(__('company.fields.legal_form'))
                    ->options(LegalForm::class)
                    ->required()
                    // The register and management sections appear and
                    // disappear with this, so the form has to re-render on
                    // change.
                    ->live(),
            ]),

            Section::make(__('company.sections.address'))->schema([
                TextInput::make('street')
                    ->label(__('company.fields.street'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('postal_code')
                    ->label(__('company.fields.postal_code'))
                    ->required()
                    ->maxLength(10),
                TextInput::make('city')
                    ->label(__('company.fields.city'))
                    ->required()
                    ->maxLength(255),
            ]),

            Section::make(__('company.sections.tax'))->schema([
                Select::make('vat_scheme')
                    ->label(__('company.fields.vat_scheme'))
                    ->options(VatScheme::class)
                    ->required(),
                TextInput::make('tax_number')
                    ->label(__('company.fields.tax_number'))
                    // Which identifier a company has depends on its scheme, so
                    // each is required only while the other is missing.
                    ->requiredWithout('vat_id')
                    ->maxLength(50),
                TextInput::make('vat_id')
                    ->label(__('company.fields.vat_id'))
                    ->requiredWithout('tax_number')
                    ->maxLength(50),
            ]),

            Section::make(__('company.sections.register'))
                ->visible(fn (Get $get): bool => $this->isRegisteredForm($get))
                ->schema([
                    TextInput::make('register_court')
                        ->label(__('company.fields.register_court'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('register_number')
                        ->label(__('company.fields.register_number'))
                        ->required()
                        ->maxLength(50),
                ]),

            Section::make(__('company.sections.management'))
                ->visible(fn (Get $get): bool => $this->isRegisteredForm($get))
                ->schema([
                    TextInput::make('managing_directors')
                        ->label(__('company.fields.managing_directors'))
                        ->helperText(__('company.fields.managing_directors_help'))
                        ->required()
                        ->maxLength(255),
                ]),

            Section::make(__('company.sections.bank'))->schema([
                TextInput::make('bank_name')
                    ->label(__('company.fields.bank_name'))
                    ->maxLength(255),
                TextInput::make('iban')
                    ->label(__('company.fields.iban'))
                    ->rule(new Iban)
                    ->maxLength(42),
                TextInput::make('bic')
                    ->label(__('company.fields.bic'))
                    ->maxLength(11),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $legalForm = $data['legal_form'] ?? null;

        if (! $legalForm instanceof LegalForm) {
            $legalForm = LegalForm::tryFrom((string) $legalForm);
        }

        if ($legalForm?->isRegistered() === true) {
            return $data;
        }

        // A hidden Filament field is not dehydrated, so switching a GmbH to a
        // sole proprietorship would otherwise leave its HRB number in the
        // database — where the next document would print it.
        return [
            ...$data,
            'register_court' => null,
            'register_number' => null,
            'managing_directors' => null,
        ];
    }

    private function isRegisteredForm(Get $get): bool
    {
        $legalForm = $get('legal_form');

        if (! $legalForm instanceof LegalForm) {
            $legalForm = LegalForm::tryFrom((string) $legalForm);
        }

        return $legalForm?->isRegistered() ?? false;
    }
}
```

- [ ] **Step 4: Add the German strings**

In `lang/de/company.php`, add a `sections` key and extend `fields`:

```php
    'sections' => [
        'identity' => 'Firma',
        'address' => 'Adresse',
        'tax' => 'Steuer',
        'register' => 'Handelsregister',
        'management' => 'Geschäftsführung',
        'bank' => 'Bankverbindung',
    ],
```

and inside `fields`:

```php
        'slug' => 'Kurzname (URL)',
        'slug_help' => 'Wird einmal aus dem Namen gebildet und ändert sich nie.',
        'street' => 'Straße und Hausnummer',
        'postal_code' => 'PLZ',
        'city' => 'Ort',
        'vat_scheme' => 'Besteuerung',
        'tax_number' => 'Steuernummer',
        'vat_id' => 'USt-IdNr.',
        'register_court' => 'Registergericht',
        'register_number' => 'Registernummer',
        'managing_directors' => 'Geschäftsführer',
        'managing_directors_help' => 'Mehrere durch Komma trennen. Erscheint so auf der Rechnung.',
        'bank_name' => 'Bank',
        'iban' => 'IBAN',
        'bic' => 'BIC',
```

- [ ] **Step 5: Run the test to verify it passes**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanySettingsTest.php
```

Expected: PASS, 11 tests.

If `insists on at least one tax identifier` reports no errors, `requiredWithout()` is being given an absolute path — it takes the path relative to the form's state, which is what the code above passes.

- [ ] **Step 6: Run the toolchain**

```sh
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

Expected: clean, whole suite green.

- [ ] **Step 7: Commit**

```sh
git add app/Filament/Pages/Tenancy/CompanySettings.php lang tests/Feature/CompanySettingsTest.php
git commit -m "feat: add the company settings page

One editing surface for a company, at /admin/{company}/settings. The
register and management sections exist only for legal forms that are in
the Handelsregister, and switching away from one clears its data rather
than leaving an HRB number to print on a later document.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: The companies list

**Files:**
- Create: `app/Filament/Resources/Companies/CompanyResource.php`
- Create: `app/Filament/Resources/Companies/Pages/ListCompanies.php`
- Create: `app/Filament/Resources/Companies/Tables/CompaniesTable.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (the tenant-menu entry)
- Modify: `lang/de/company.php`
- Test: `tests/Feature/CompanyListTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–6.
- Produces: `/admin/{company}/companies`, a list page that is **not** scoped to the tenant, with row actions `open`, `archive`, `unarchive`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/CompanyListTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('lists companies other than the one being viewed', function (): void {
    // The whole point of marking the resource unscoped. Left scoped, Filament
    // constrains the query to the current tenant and the list shows exactly
    // one row — the company you are already in — which makes switching
    // impossible.
    $current = Company::factory()->create(['name' => 'Erste GmbH']);
    $other = Company::factory()->create(['name' => 'Zweite GmbH']);

    $user = User::factory()->create();
    $user->companies()->attach([$current->getKey(), $other->getKey()]);

    Filament::setTenant($current);

    Livewire::actingAs($user)
        ->test(ListCompanies::class)
        ->assertCanSeeTableRecords([$current, $other]);
});

it('archives a company from the row actions', function (): void {
    $current = Company::factory()->create(['name' => 'Erste GmbH']);
    $other = Company::factory()->create(['name' => 'Zweite GmbH']);

    $user = User::factory()->create();
    $user->companies()->attach([$current->getKey(), $other->getKey()]);

    Filament::setTenant($current);

    Livewire::actingAs($user)
        ->test(ListCompanies::class)
        ->callTableAction('archive', $other);

    expect($other->fresh()?->isArchived())->toBeTrue();
});

it('hides the archive action on the last active company', function (): void {
    // The guard lives on the model, which throws. Hiding the action is what
    // keeps the user from meeting that exception as a 500.
    $only = Company::factory()->create();

    $user = User::factory()->create();
    $user->companies()->attach($only);

    Filament::setTenant($only);

    Livewire::actingAs($user)
        ->test(ListCompanies::class)
        ->assertTableActionHidden('archive', $only);
});

it('offers unarchive only on an archived company', function (): void {
    $active = Company::factory()->create();
    $archived = Company::factory()->archived()->create();

    $user = User::factory()->create();
    $user->companies()->attach([$active->getKey(), $archived->getKey()]);

    Filament::setTenant($active);

    Livewire::actingAs($user)
        ->test(ListCompanies::class)
        ->assertTableActionVisible('unarchive', $archived)
        ->assertTableActionHidden('unarchive', $active);
});

it('keeps working after archiving the company being viewed', function (): void {
    // Filament still has this tenant set for the rest of the request, while it
    // has just left getTenants(). The page must render rather than blow up on
    // a tenant that is no longer switchable.
    $current = Company::factory()->create(['name' => 'Erste GmbH']);
    $other = Company::factory()->create(['name' => 'Zweite GmbH']);

    $user = User::factory()->create();
    $user->companies()->attach([$current->getKey(), $other->getKey()]);

    Filament::setTenant($current);

    Livewire::actingAs($user)
        ->test(ListCompanies::class)
        ->callTableAction('archive', $current)
        ->assertSuccessful();

    expect($current->fresh()?->isArchived())->toBeTrue();

    // And its URL still resolves, because its documents stay readable.
    $this->actingAs($user)
        ->get('/admin/erste-gmbh/companies')
        ->assertOk();
});

it('serves the list under the company slug', function (): void {
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($company);

    $this->actingAs($user)
        ->get('/admin/acme-gmbh/companies')
        ->assertOk()
        ->assertSee('Acme GmbH');
});
```

- [ ] **Step 2: Run the test to verify it fails**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyListTest.php
```

**If a Livewire page test fails with a missing current panel**, call
`Filament::setCurrentPanel('admin')` before `Filament::setTenant(...)`. The
admin panel is registered with `->default()`, so it normally resolves on its
own; the explicit call is the fix if it does not.


Expected: FAIL with `Class "App\Filament\Resources\Companies\Pages\ListCompanies" not found`.

- [ ] **Step 3: Write the table**

Create `app/Filament/Resources/Companies/Tables/CompaniesTable.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Tables;

use App\Filament\Pages\Tenancy\CompanySettings;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CompaniesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('company.fields.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('legal_form')
                    ->label(__('company.fields.legal_form'))
                    ->badge(),
                TextColumn::make('archived_at')
                    ->label(__('company.fields.archived_at'))
                    // No ->placeholder(): TextColumn has no such method in
                    // Filament 5. An active company simply shows an empty cell.
                    ->since(),
            ])
            ->defaultSort('name')
            // No filters and no created_at column. With a handful of rows they
            // would be speculative, and a filter nobody asked for is the sort
            // of affordance that is much harder to remove than to add.
            ->recordActions([
                // One vertical-ellipsis dropdown, never a row of buttons. Loose
                // row actions are a ratchet: every feature adds one, none ever
                // removes one, and the destructive action ends up a few pixels
                // from the common one.
                ActionGroup::make([
                    Action::make('open')
                        ->label(__('company.actions.open'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                        ->url(fn (Company $record): string => route(
                            CompanySettings::getRouteName(),
                            ['tenant' => $record],
                        )),
                    Action::make('archive')
                        ->label(__('company.actions.archive'))
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->requiresConfirmation()
                        // Hidden rather than disabled: the model throws on the
                        // last active company, and an action the user cannot
                        // take is not worth showing.
                        ->visible(fn (Company $record): bool => $record->canBeArchived())
                        ->action(fn (Company $record) => $record->archive()),
                    Action::make('unarchive')
                        ->label(__('company.actions.unarchive'))
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->visible(fn (Company $record): bool => $record->isArchived())
                        ->action(fn (Company $record) => $record->unarchive()),
                ]),
            ])
            ->emptyStateHeading(__('company.list.empty'));
    }
}
```

- [ ] **Step 4: Write the resource and its page**

Create `app/Filament/Resources/Companies/CompanyResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies;

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Filament\Resources\Companies\Tables\CompaniesTable;
use App\Models\Company;
use Filament\Resources\Resource;
use Filament\Tables\Table;

/**
 * The companies list.
 *
 * A list page and nothing else. Creating happens on the tenant-registration
 * page and editing on the tenant-profile page, so a create, view or edit page
 * here would be a second surface onto the same record.
 */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    /**
     * Companies are what the tenant *is*, so scoping this resource to the
     * current tenant would reduce the list to the single company the user is
     * already inside — and make switching impossible.
     */
    protected static bool $isScopedToTenant = false;

    /**
     * Reached from the "Manage companies" entry in the tenant menu instead.
     * The sidebar is left empty for the company-scoped work of the next wave.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('company.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('company.resource.plural_label');
    }

    public static function table(Table $table): Table
    {
        return CompaniesTable::configure($table);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
        ];
    }
}
```

Create `app/Filament/Resources/Companies/Pages/ListCompanies.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Pages\Tenancy\RegisterCompany;
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCompanies extends ListRecords
{
    protected static string $resource = CompanyResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            // A link to the registration page rather than a create form of its
            // own: one creation path means one form to keep correct.
            Action::make('register')
                ->label(__('company.actions.create'))
                ->url(fn (): string => RegisterCompany::getUrl()),
        ];
    }
}
```

- [ ] **Step 5: Add the tenant-menu entry and the German strings**

In `app/Providers/Filament/AdminPanelProvider.php`, add to the imports:

```php
use App\Filament\Resources\Companies\CompanyResource;
use Filament\Navigation\MenuItem;
use Filament\Support\Icons\Heroicon;
```

and add after `->tenantProfile(CompanySettings::class)`:

```php
            ->tenantMenuItems([
                MenuItem::make()
                    ->label(fn (): string => __('company.actions.manage'))
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    // A closure so the URL is built when the menu renders and
                    // a tenant is in the route.
                    ->url(fn (): string => CompanyResource::getUrl('index')),
            ])
```

In `lang/de/company.php`, add:

```php
    'resource' => [
        'label' => 'Firma',
        'plural_label' => 'Firmen',
    ],

    'actions' => [
        'open' => 'Öffnen',
        'archive' => 'Deaktivieren',
        'unarchive' => 'Wieder aktivieren',
        'create' => 'Neue Firma',
        'manage' => 'Firmen verwalten',
    ],

    'list' => [
        'empty' => 'Noch keine Firma angelegt.',
    ],
```

and inside `fields`:

```php
        'archived_at' => 'Deaktiviert',
```

- [ ] **Step 6: Run the test to verify it passes**

```sh
docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyListTest.php
```

Expected: PASS, 6 tests.

If `lists companies other than the one being viewed` sees only one record, `$isScopedToTenant = false` is missing.

- [ ] **Step 7: Run the toolchain**

```sh
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

Expected: clean, whole suite green.

- [ ] **Step 8: Look at the screens**

Tests do not tell you whether a form reads well.

```sh
docker compose up -d
```

Log in at <http://localhost:8080/admin>, then check by eye:

1. With no company, you land on company registration.
2. Creating a `GmbH` lands you on its settings, with Handelsregister and Geschäftsführung sections present.
3. Switching the legal form to `Einzelunternehmen` makes both sections disappear.
4. Umlauts in the name and street render correctly — not as `Ã¶`.
5. The tenant menu lists the company, "Neue Firma" and "Firmen verwalten".
6. Each row in the companies list has one ⋮ button, not a row of buttons.

- [ ] **Step 9: Commit**

```sh
git add app/Filament app/Providers/Filament/AdminPanelProvider.php lang tests/Feature/CompanyListTest.php
git commit -m "feat: list companies, and archive them from the list

The resource is unscoped: companies are what the tenant is, so scoping it
would show only the company you are already inside. One list page, one ⋮
dropdown, and no create or edit page of its own — registration and
settings already own those.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: Correct the documents this wave falsified

No tests. The deliverable is that nothing in the repository or in Outline still claims something this wave made untrue. §10 of the spec is the list.

**Files:**
- Modify: `CLAUDE.md`
- Modify: `README.md`
- Modify: `docs/superpowers/specs/2026-09-23-invoice-system-design.md`
- Modify: `.ai/guidelines/laravel/core.blade.php` (then regenerate `AGENTS.md`)
- Modify: the Outline collection **Invoice**

- [ ] **Step 1: Correct `CLAUDE.md`**

Four edits:

1. The opening paragraph says "**Current state: the development environment only.** No domain model exists yet — no companies, customers, documents, numbering or money handling." Companies now exist. Rewrite it to say that companies and the tenancy backbone are in place, and that customers, documents, numbering and money handling are not.

2. The non-negotiable bullet "**The current company lives in the URL, not only the session.**" gives the examples `/{company}/invoices` and `/{company}/settings`. The panel keeps its `/admin` prefix, so these are `/admin/{company}/invoices` and `/admin/{company}/settings`. Keep the reasoning; change the paths, and note that the prefix leaves the root free for a real 404.

3. Under **Known gaps**, the `User::canAccessPanel()` entry says it "must become a real check when tenancy lands". Tenancy has landed. Replace it with the truth: the boundary is `canAccessTenant()`, which checks the join table; `canAccessPanel()` stays permissive because there is no registration and no user state to gate on, and a condition invented for it would be theatre.

4. Add a new non-negotiable decision:

```markdown
- **Identifiers are English; German is for labels and legal designations.**
  Columns, enums, classes and methods are named in English. German appears in
  user-facing labels, which live in `lang/de/`, and in legal designations that
  print verbatim and have no English equivalent — `GmbH` is a name, not a word.
  So the §19 UStG flag is a `vat_scheme` enum, and the document types of the
  next wave are `CancellationInvoice`, `CreditNote` and `PaymentReminder`
  rather than Storno, Gutschrift and Mahnung. Deciding this per column is how
  a codebase ends up bilingual.
```

- [ ] **Step 2: Correct the system design spec**

In `docs/superpowers/specs/2026-09-23-invoice-system-design.md`:

1. §3.2 writes `/{company}/invoices`, `/{company}/customers`, `/{company}/settings`. Correct them to carry the `/admin` prefix, and add a sentence saying the property being protected is that the company is in the path rather than in session state, which the prefix does not weaken. Follow the file's existing convention for a corrected claim — see the indented note in §3.1, which says what changed rather than quietly rewriting.

2. §15's decision table row "Company in the URL" says `/{company}/invoices`. Correct it, and add two rows:

```markdown
| Panel path | Keeps Filament's `/admin` prefix; company-scoped screens are `/admin/{company}/…` |
| Identifier language | English, except legal designations that print verbatim |
```

- [ ] **Step 3: Check `README.md`**

Lines 38–42 describe creating a user and logging in at `/admin`. That is still where login lives, but a fresh user with no company now lands on company registration rather than a dashboard. Add a sentence saying so, so the first-run sequence does not read as though something has gone wrong.

- [ ] **Step 4: Record the naming convention for agents**

`AGENTS.md` is generated — never edit it directly. Add the English-identifiers convention to `.ai/guidelines/laravel/core.blade.php`, then regenerate:

```sh
docker compose run --rm app php artisan boost:install --guidelines --no-interaction
```

Then diff `AGENTS.md` and confirm the new text landed and nothing else moved:

```sh
git diff AGENTS.md
```

- [ ] **Step 5: Patch Outline**

Nothing syncs Outline. Search the **Invoice** collection for the terms this wave changed — the URL shape, `Kleinunternehmer`, and the tenancy description — and patch the pages that carry them.

Use the `outline` MCP server. Fetch the collection by its id, not its URL, and use `update_document` with `editMode: "patch"` so the pages' formatting survives:

- collection: `invoice-BgP8lxR8dF` at <https://heimdall.tail1ec8f7.ts.net/collection/invoice-BgP8lxR8dF>

Do not touch dated plan or task documents, in the repo or in Outline. They are history.

- [ ] **Step 6: Verify the whole suite one more time**

```sh
docker compose run --rm app ./vendor/bin/pest
```

Expected: everything green. Documentation edits cannot break it, which is exactly why this runs before the commit that claims the wave is done.

- [ ] **Step 7: Commit**

```sh
git add CLAUDE.md README.md docs/superpowers/specs AGENTS.md .ai/guidelines
git commit -m "docs: correct what the companies wave falsified

The panel keeps its /admin prefix, so §3.2's URL shape was wrong as
written. canAccessPanel is no longer a gap waiting on tenancy — the
boundary is canAccessTenant, and saying so is more honest than inventing
a condition for it. Also records that identifiers are English, before the
next wave reaches Storno and Gutschrift.

Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>"
```

---

## Done when

- `docker compose run --rm app ./vendor/bin/pest` is green, including the four isolation and URL tests that are the point of the wave.
- PHPStan is clean at level 8 with no new baseline entries.
- A real company of each legal form can be created and completed through the interface, and the rendered screens have been looked at.
- Nothing in `CLAUDE.md`, `README.md`, the specs, `AGENTS.md` or Outline still describes the world as it was before this wave.
