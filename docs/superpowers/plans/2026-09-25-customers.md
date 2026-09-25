# Customers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every company its own customers — list, create, view, edit, deactivate — as the first company-owned model, with a per-company customer number that is also the customer's URL.

**Architecture:** One `Customer` model (UUID v7 key, `company_id`, integer `number`) owned by `Company`. `Customer` assigns its own number in a `creating` hook under a lock on the company row, and formats and parses the `K-0004` form that serves as its route key. A tenant-aware Filament resource (`CustomerResource`) supplies the four pages; Filament's tenancy scopes every query and every URL lookup to the company in the path.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, PostgreSQL, Pest 4, Larastan level 8, Rector, Pint. Everything runs in Docker.

**Spec:** `docs/superpowers/specs/2026-09-25-customers-design.md` — this plan argues from it; read both. Its parent is `docs/superpowers/specs/2026-09-23-invoice-system-design.md`.

## Global Constraints

Every task's requirements implicitly include all of these.

- **Every command runs in the container:** `docker compose run --rm app <command>`. Nothing is installed on the host.
- **Do not use `php artisan make:*` generators.** The container runs as root, so generated files are root-owned and cannot be edited or deleted from the host. Create every file with the editor, from the code in this plan.
- **PostgreSQL only, never SQLite, including in tests.** `phpunit.xml` already points Pest at `invoice_test`.
- **UUID v7 primary keys via `HasUuids`.** The UUID *is* the key; foreign keys carry it.
- **Identifiers are English; German only in `lang/de/customer.php`.** No German string literal in `app/`.
- **Row actions live in one `ActionGroup`.** Never loose buttons across a row.
- **Show less rather than more.** No filters, no bulk actions, no `created_at` column, no global search.
- **Larastan stays at level 8.** Fix findings; do not baseline anything this plan introduces.
- **Rector before Pint**, both before committing: `./vendor/bin/rector process`, then `./vendor/bin/pint`. Rector covers `app/` and `tests/` only.
- **`declare(strict_types=1);` in every file under `app/` and `tests/`.** Migrations and factories follow their directories' existing convention, which omits it.
- **Every test must be able to fail.** Each test below says what breaks it; keep that comment when you write it.
- **Work on the branch `feat/customers`**, created from `main` before Task 1.
- **Git has no identity configured on this machine.** Commit with the identity of the existing commits: `git -c user.name="$(git log -1 --format=%an)" -c user.email="$(git log -1 --format=%ae)" commit …`. End every commit message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

The standard check, referred to below as **"run the checks"**:

```sh
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

## Review Focus

Five inputs the spec implies but does not spell out, most likely to bite first. Each is pinned by a test in the task that owns the code.

1. **A search must not escape the tenant.** A search that ORs its conditions onto the query without grouping them reads `company = A AND name ILIKE x OR city ILIKE x`, which lists company B's customers whose city matches. Pinned to Task 4.
2. **A search with no match is not an empty company.** Filament shows the same empty state for "no rows" and "no rows matching", so without care a search for a typo announces "Noch keine Kunden angelegt." and offers to create the first customer. Pinned to Task 4.
3. **Customer names carry `&`, `<` and umlauts** — "Bauer & Kollegen GmbH", "Müller & Söhne". The name is rendered through an `HtmlString` (to put the Deaktiviert badge beside it), so it must be escaped exactly once: not raw, not `&amp;amp;`. Pinned to Task 4 (list and heading); the umlaut round trip to Task 3.
4. **A hand-edited URL is a 404, never a 500.** `K-99999999999` overflows Postgres' `integer`, `K-abc` is not a number, and the UUID must not work either. Pinned to Task 1 (parser) and Task 2 (HTTP).
5. **Editing an existing customer changes neither its number nor its deactivation.** Saving the edit form of a deactivated customer must leave it deactivated and keep its number. Pinned to Task 3.

---

## File Structure

**Created:**

| File | Responsibility |
|---|---|
| `app/Enums/CustomerType.php` | Firma or Privatperson; the one predicate `isBusiness()`; label and badge colour |
| `app/Models/Customer.php` | The customer: numbering under lock, route key format and parsing, clearing business-only fields, deactivation |
| `database/migrations/2026_09_25_100000_create_customers_table.php` | The table and the `(company_id, number)` unique index |
| `database/factories/CustomerFactory.php` | A complete business customer; `privatePerson()` and `archived()` states |
| `lang/de/customer.php` | Every German string this wave puts on screen |
| `app/Filament/Resources/Customers/CustomerResource.php` | Resource declaration, labels, navigation, pages; `nameWithStatus()` |
| `app/Filament/Resources/Customers/Pages/ListCustomers.php` | List page; header action |
| `app/Filament/Resources/Customers/Pages/CreateCustomer.php` | Create page; associates the tenant before saving |
| `app/Filament/Resources/Customers/Pages/EditCustomer.php` | Edit page; redirects to the view page |
| `app/Filament/Resources/Customers/Pages/ViewCustomer.php` | View page; title, heading, header actions |
| `app/Filament/Resources/Customers/Schemas/CustomerForm.php` | The create/edit form |
| `app/Filament/Resources/Customers/Schemas/CustomerInfolist.php` | The read-only view |
| `app/Filament/Resources/Customers/Tables/CustomersTable.php` | Columns, search, empty state, row actions |
| `app/Filament/Resources/Customers/Actions/CustomerActions.php` | Deactivate / reactivate, shared by the list rows and the view page |
| `tests/Feature/CustomerTest.php` | Model-level behaviour |
| `tests/Feature/CustomerRoutingTest.php` | URLs, scoping by URL, view page |
| `tests/Feature/CustomerFormTest.php` | Create and edit |
| `tests/Feature/CustomerListTest.php` | List, search, empty state, deactivation |

**Modified:**

| File | Change |
|---|---|
| `app/Models/Company.php` | `customers()` relationship |
| `tests/Feature/UuidKeysTest.php` | `customers` joins the UUID checks |
| `tests/Pest.php` | `memberOf()` and `actInCompany()` helpers |
| `README.md`, `CLAUDE.md`, `docs/superpowers/specs/2026-09-23-invoice-system-design.md`, Outline | Task 5 |

---

### Task 1: Customer model, numbering and route key format

**Files:**
- Create: `app/Enums/CustomerType.php`
- Create: `app/Models/Customer.php`
- Create: `database/migrations/2026_09_25_100000_create_customers_table.php`
- Create: `database/factories/CustomerFactory.php`
- Create: `lang/de/customer.php`
- Modify: `app/Models/Company.php` (add `customers()`)
- Modify: `tests/Feature/UuidKeysTest.php`
- Test: `tests/Feature/CustomerTest.php`

**Interfaces:**
- Consumes: `App\Models\Company` and its factory (existing).
- Produces:
  - `enum App\Enums\CustomerType: string` — cases `Business = 'business'`, `PrivatePerson = 'private_person'`; `isBusiness(): bool`; `static fromFormState(mixed $state): ?self`; `getLabel(): string`; `getColor(): string`.
  - `App\Models\Customer` — attributes `id, company_id, number (int), type (CustomerType), name, contact_person, vat_id, street, postal_code, city, email, archived_at`; `static formatNumber(int): string`; `static numberFromRouteKey(string): ?int`; `static numberFromSearch(string): ?int`; `formattedNumber(): string`; `getRouteKey(): string` (returns `K-0004`); `isArchived(): bool`; `archive(): void`; `unarchive(): void`; `company(): BelongsTo`.
  - `Company::customers(): HasMany`.
  - `Database\Factories\CustomerFactory` — states `privatePerson()`, `archived()`. Use as `Customer::factory()->for($company)->create()`.
  - `lang/de/customer.php` — full key set, listed in Step 3.

- [ ] **Step 1: Write the failing model tests**

Create `tests/Feature/CustomerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\CustomerType;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

it('numbers customers per company, starting at one', function (): void {
    // Fails against a single global sequence, which would give B's first
    // customer the number 3.
    $a = Company::factory()->create();
    $b = Company::factory()->create();

    $first = Customer::factory()->for($a)->create();
    $second = Customer::factory()->for($a)->create();
    $otherCompanysFirst = Customer::factory()->for($b)->create();

    expect([$first->number, $second->number, $otherCompanysFirst->number])
        ->toBe([1, 2, 1]);
});

it('assigns the number even when one is supplied', function (): void {
    // Factories run unguarded, so this passes a number straight past the
    // fillable list. Only the creating hook overwriting it keeps it from
    // landing — which is the property: a number is assigned, never chosen.
    $company = Company::factory()->create();

    $customer = Customer::factory()->for($company)->create(['number' => 99]);

    expect($customer->number)->toBe(1);
});

it('keeps its number when saved again', function (): void {
    // Fails if numbering runs on every save rather than on creation only.
    $company = Company::factory()->create();
    $first = Customer::factory()->for($company)->create();
    Customer::factory()->for($company)->create();

    $first->update(['name' => 'Umbenannt GmbH']);

    expect($first->fresh()?->number)->toBe(1);
});

it('refuses a customer without a company', function (): void {
    // A customer is numbered within its company. Without one there is no
    // sequence to take a number from, and a clear refusal beats the NOT NULL
    // violation that would follow.
    expect(fn () => Customer::factory()->make(['company_id' => null])->save())
        ->toThrow(LogicException::class);
});

it('backs the numbering with a unique index', function (): void {
    // The lock is what keeps numbers unique in normal operation; this index is
    // what keeps a bypassed lock from producing two customers with one number.
    // No happy-path test notices the index is missing. Written straight to the
    // table, because the model would renumber the row.
    $company = Company::factory()->create();
    $existing = Customer::factory()->for($company)->create();

    expect(fn () => DB::table('customers')->insert([
        'id' => (string) Str::uuid7(),
        'company_id' => $company->getKey(),
        'number' => $existing->number,
        'type' => CustomerType::Business->value,
        'name' => 'Doppelt GmbH',
        'street' => 'Hauptstraße 1',
        'postal_code' => '84028',
        'city' => 'Landshut',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('clears the business-only fields when a customer becomes a private person', function (): void {
    // Both directions: clearing on a switch, and keeping on an ordinary save.
    // Either half alone passes against "always clears" or "never clears".
    $company = Company::factory()->create();
    $switched = Customer::factory()->for($company)->create([
        'contact_person' => 'Sofia Kraus',
        'vat_id' => 'DE123456789',
    ]);
    $kept = Customer::factory()->for($company)->create([
        'contact_person' => 'Markus Brenner',
        'vat_id' => 'DE987654321',
    ]);

    $switched->update(['type' => CustomerType::PrivatePerson]);
    $kept->update(['name' => 'Neuer Name GmbH']);

    expect($switched->fresh()?->contact_person)->toBeNull()
        ->and($switched->fresh()?->vat_id)->toBeNull()
        ->and($kept->fresh()?->contact_person)->toBe('Markus Brenner')
        ->and($kept->fresh()?->vat_id)->toBe('DE987654321');
});

it('formats numbers with at least four digits', function (int $number, string $formatted): void {
    // 10000 is the case a fixed-width format would truncate or reject.
    expect(Customer::formatNumber($number))->toBe($formatted);
})->with([
    [1, 'K-0001'],
    [4, 'K-0004'],
    [9999, 'K-9999'],
    [10000, 'K-10000'],
]);

it('uses the formatted number as its route key', function (): void {
    $customer = Customer::factory()->for(Company::factory())->create();

    expect($customer->getRouteKey())->toBe('K-0001');
});

it('reads a route key strictly', function (string $key, ?int $number): void {
    // Eleven digits would overflow Postgres' integer column and turn a 404
    // into a 500; the UUID must not open a customer at all.
    expect(Customer::numberFromRouteKey($key))->toBe($number);
})->with([
    ['K-0004', 4],
    ['K-4', 4],
    ['K-10000', 10000],
    ['k-0004', null],
    ['0004', null],
    ['K-', null],
    ['K-abc', null],
    ['K-0004 ', null],
    ['K-99999999999', null],
    ['01999b2c-7d8e-7a3f-9c1d-2e3f4a5b6c7d', null],
]);

it('reads a search term leniently', function (string $search, ?int $number): void {
    // The search box takes the number however it is typed. "Müller" and "K-"
    // are not numbers and must not become 0.
    expect(Customer::numberFromSearch($search))->toBe($number);
})->with([
    ['K-0004', 4],
    ['k-0004', 4],
    ['0004', 4],
    ['4', 4],
    [' 4 ', 4],
    ['K-', null],
    ['Müller', null],
    ['99999999999', null],
]);

it('archives and restores a customer, keeping the first archive date', function (): void {
    /** @var TestCase $this */
    $customer = Customer::factory()->for(Company::factory())->create();

    $this->travelTo(now()->subDay());
    $customer->archive();
    $archivedAt = $customer->fresh()?->archived_at;
    $this->travelBack();

    $customer->archive();

    expect($customer->fresh()?->archived_at?->equalTo($archivedAt))->toBeTrue();

    $customer->unarchive();

    expect($customer->fresh()?->isArchived())->toBeFalse();
});
```

Append to `tests/Feature/UuidKeysTest.php`:

```php
it('gives customers a uuid key and a uuid company foreign key', function (): void {
    // Both halves, because the foreign key is the one that silently stays
    // bigint while the primary key is migrated.
    $columns = DB::select(
        'select column_name, data_type from information_schema.columns
         where table_name = ? and column_name in (?, ?)',
        ['customers', 'id', 'company_id']
    );

    expect($columns)->toHaveCount(2);

    foreach ($columns as $column) {
        expect($column->data_type)->toBe('uuid');
    }
});

it('generates version 7 uuids for customers', function (): void {
    // The version nibble is the only thing that separates v7 from v4.
    $customer = Customer::factory()->for(Company::factory())->create();

    expect($customer->getKey()[14])->toBe('7');
});
```

and add `use App\Models\Company;` and `use App\Models\Customer;` to its imports.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CustomerTest.php tests/Feature/UuidKeysTest.php`
Expected: FAIL — `Class "App\Models\Customer" not found`.

- [ ] **Step 3: Write the enum, the labels, the migration, the model and the factory**

Create `app/Enums/CustomerType.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasColor, HasLabel
{
    case Business = 'business';
    case PrivatePerson = 'private_person';

    /**
     * Coerces a form's state — the enum itself, its backing string, or
     * nothing yet — to a type. Forms hold whichever of these they were last
     * given, so a closure that asks the state a question has to accept all
     * three.
     */
    public static function fromFormState(mixed $state): ?self
    {
        if ($state instanceof self) {
            return $state;
        }

        return is_string($state) ? self::tryFrom($state) : null;
    }

    /**
     * Whether this customer can carry a contact person and a VAT ID.
     *
     * The one question both the form (render the fields?) and the model
     * (clear them on save?) ask. Asking it here keeps the two from disagreeing
     * about which types have them.
     */
    public function isBusiness(): bool
    {
        return match ($this) {
            self::Business => true,
            self::PrivatePerson => false,
        };
    }

    public function getLabel(): string
    {
        return __("customer.type.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Business => 'info',
            self::PrivatePerson => 'gray',
        };
    }
}
```

Create `lang/de/customer.php` with every key this wave uses:

```php
<?php

declare(strict_types=1);

return [
    'label' => 'Kunde',
    'plural_label' => 'Kunden',

    'type' => [
        'business' => 'Firma',
        'private_person' => 'Privatperson',
    ],

    'status' => [
        'archived' => 'Deaktiviert',
    ],

    'list' => [
        'empty' => 'Noch keine Kunden angelegt.',
        'empty_description' => 'Jede Rechnung geht an einen Kunden. Legen Sie den ersten an.',
        'no_results' => 'Keine Kunden gefunden.',
    ],

    'actions' => [
        'create' => 'Neuer Kunde',
        'open' => 'Öffnen',
        'edit' => 'Bearbeiten',
        'archive' => 'Deaktivieren',
        'unarchive' => 'Wieder aktivieren',
    ],

    'sections' => [
        'customer' => 'Kunde',
        'address' => 'Adresse',
        'contact' => 'Kontakt',
    ],

    'fields' => [
        'number' => 'Kundennr.',
        'type' => 'Typ',
        'name' => 'Name',
        'contact_person' => 'Ansprechpartner',
        'vat_id' => 'USt-IdNr.',
        'street' => 'Straße und Hausnummer',
        'postal_code' => 'PLZ',
        'city' => 'Ort',
        'email' => 'E-Mail',
    ],
];
```

Create `database/migrations/2026_09_25_100000_create_customers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Restrict, not cascade: companies are deactivated, never deleted,
            // and a customer an issued document refers to must never vanish
            // with one.
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            // An integer, displayed as K-0004. A string would sort K-10000
            // before K-9999 and make max() + 1 wrong past 9999.
            $table->integer('number');
            $table->string('type');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('vat_id')->nullable();
            $table->string('street');
            $table->string('postal_code');
            $table->string('city');
            $table->string('email')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            // The backstop for numbering: were the lock ever bypassed, a race
            // fails the save instead of producing two customers with one
            // number. Its leading column also serves every tenant-scoped query.
            $table->unique(['company_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
```

Create `app/Models/Customer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomerType;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Declared because Larastan types these from the migration rather than from
 * casts(), as it does for Company::$legal_form.
 *
 * @property CustomerType $type
 * @property int $number
 */
#[Fillable([
    'type',
    'name',
    'contact_person',
    'vat_id',
    'street',
    'postal_code',
    'city',
    'email',
])]
class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use HasFactory, HasUuids;

    /**
     * `number`, `company_id` and `archived_at` are absent from the fillable
     * list above: the number is assigned here, the company is the tenant, and
     * `archived_at` changes only through archive() and unarchive().
     *
     * @var array<string, mixed>
     */
    #[\Override]
    protected $attributes = [
        'type' => 'business',
    ];

    public static function formatNumber(int $number): string
    {
        return 'K-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * The number in a URL: exactly `K-` and digits, the way formatNumber()
     * writes it. At most nine digits, so a hand-edited URL cannot overflow
     * Postgres' integer column and turn a 404 into a 500.
     */
    public static function numberFromRouteKey(string $key): ?int
    {
        return preg_match('/^K-(\d{1,9})$/', $key, $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    /**
     * The number as someone types it into the search box: `K-0004`,
     * `k-0004`, `0004` or `4`.
     */
    public static function numberFromSearch(string $search): ?int
    {
        return preg_match('/^(?:K-)?(\d{1,9})$/i', trim($search), $matches) === 1
            ? (int) $matches[1]
            : null;
    }

    public function formattedNumber(): string
    {
        return self::formatNumber($this->number);
    }

    /**
     * Customer URLs carry the number, not the UUID — customers spec §3.4
     * argues why that does not breach §3.1 of the system design. The UUID is
     * still the key; this is only what a URL shows.
     */
    #[\Override]
    public function getRouteKeyName(): string
    {
        return 'number';
    }

    #[\Override]
    public function getRouteKey(): string
    {
        return $this->formattedNumber();
    }

    /**
     * Turns `K-0004` back into `number = 4`. Anything else matches nothing,
     * so it is a 404. Filament resolves a resource record through this, on a
     * query already scoped to the company in the URL.
     *
     * @param  mixed  $query
     * @param  mixed  $value
     * @param  string|null  $field
     * @return mixed
     */
    #[\Override]
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $number = self::numberFromRouteKey((string) $value);

        return $number === null
            ? $query->whereRaw('1 = 0')
            : $query->where('number', $number);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Deactivates the customer. An already deactivated customer keeps its
     * original date.
     */
    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        // forceFill because archived_at is deliberately not fillable.
        $this->forceFill(['archived_at' => now()])->save();
    }

    public function unarchive(): void
    {
        $this->forceFill(['archived_at' => null])->save();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * A new customer is saved in a transaction of its own, so that the lock
     * the `creating` hook takes on the company row is held until the INSERT
     * commits. Without it the lock would end when the hook returned, and two
     * concurrent creates could read the same maximum. Inside an outer
     * transaction this nests as a savepoint and the lock lasts until the
     * outer commit.
     *
     * @param  array<string, mixed>  $options
     */
    #[\Override]
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            return parent::save($options);
        }

        return DB::transaction(fn (): bool => parent::save($options));
    }

    protected static function booted(): void
    {
        // Overwrites unconditionally: the number is assigned, never chosen —
        // not by the form, not by a factory, not by forceFill().
        static::creating(function (Customer $customer): void {
            $customer->number = self::nextNumberFor($customer);
        });

        // A hidden form field is not dehydrated, so switching a Firma to a
        // Privatperson would otherwise leave its contact person and VAT ID in
        // the database — where a later document would print them. Done here
        // rather than in the form so that every save path clears them, and
        // decided by isBusiness(), the predicate the form renders them by.
        static::saving(function (Customer $customer): void {
            if ($customer->type->isBusiness()) {
                return;
            }

            $customer->contact_person = null;
            $customer->vat_id = null;
        });
    }

    private static function nextNumberFor(Customer $customer): int
    {
        if ($customer->company_id === null) {
            throw new LogicException('A customer is numbered within its company, so its company must be set before it is saved.');
        }

        // Serializes concurrent creates within one company and leaves every
        // other company unblocked.
        Company::query()->whereKey($customer->company_id)->lockForUpdate()->firstOrFail();

        // Unscoped on purpose: the company is named explicitly, and the answer
        // must not depend on which tenant the current request carries.
        $highest = self::query()
            ->withoutGlobalScopes()
            ->where('company_id', $customer->company_id)
            ->max('number');

        return (int) $highest + 1;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'number' => 'integer',
            'archived_at' => 'datetime',
        ];
    }
}
```

If Larastan objects to the docblock of `resolveRouteBindingQuery()`, copy the parent's docblock from `Illuminate\Database\Eloquent\Model::resolveRouteBindingQuery()` verbatim rather than baselining.

Create `database/factories/CustomerFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * A complete business customer. The number is deliberately absent: it is
     * the model's job, and a factory that set it would hide that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => CustomerType::Business,
            'name' => fake()->unique()->company(),
            'contact_person' => fake()->name(),
            'vat_id' => 'DE'.fake()->numerify('#########'),
            'street' => fake()->streetAddress(),
            'postal_code' => fake()->numerify('#####'),
            'city' => fake()->city(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    public function privatePerson(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CustomerType::PrivatePerson,
            'name' => fake()->name(),
            'contact_person' => null,
            'vat_id' => null,
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

In `app/Models/Company.php`, add the import `use Illuminate\Database\Eloquent\Relations\HasMany;` and, after `users()`:

```php
    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CustomerTest.php tests/Feature/UuidKeysTest.php`
Expected: PASS.

Then confirm the backstop test can fail: temporarily comment out `$table->unique(['company_id', 'number']);`, rerun `tests/Feature/CustomerTest.php`, see "backs the numbering with a unique index" fail, and restore the line.

- [ ] **Step 5: Run the checks and commit**

Run the checks (Global Constraints). Expected: all green.

```bash
git add app/Enums/CustomerType.php app/Models/Customer.php app/Models/Company.php \
  database/migrations/2026_09_25_100000_create_customers_table.php \
  database/factories/CustomerFactory.php lang/de/customer.php \
  tests/Feature/CustomerTest.php tests/Feature/UuidKeysTest.php
git -c user.name="$(git log -1 --format=%an)" -c user.email="$(git log -1 --format=%ae)" commit -m "feat: add customers, numbered per company

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Customer resource, view page and URLs

**Files:**
- Create: `app/Filament/Resources/Customers/CustomerResource.php`
- Create: `app/Filament/Resources/Customers/Pages/ListCustomers.php`
- Create: `app/Filament/Resources/Customers/Pages/ViewCustomer.php`
- Create: `app/Filament/Resources/Customers/Schemas/CustomerInfolist.php`
- Create: `app/Filament/Resources/Customers/Tables/CustomersTable.php` (minimal; Task 4 replaces it)
- Modify: `tests/Pest.php`
- Test: `tests/Feature/CustomerRoutingTest.php`

**Interfaces:**
- Consumes: `Customer`, `Customer::formatNumber()`, `CustomerType::isBusiness()`, `lang/de/customer.php` (Task 1).
- Produces:
  - `App\Filament\Resources\Customers\CustomerResource` with pages `index` and `view` (Task 3 adds `create` and `edit`). `CustomerResource::getUrl('view', ['record' => $customer])` → `/admin/{slug}/customers/K-0001`.
  - `App\Filament\Resources\Customers\Pages\ViewCustomer` with `getTitle(): string` and a protected `customer(): Customer`.
  - `App\Filament\Resources\Customers\Tables\CustomersTable::configure(Table $table): Table`.
  - Test helpers in `tests/Pest.php`: `memberOf(Company ...$companies): User` and `actInCompany(Company $company): User`.

The panel discovers resources under `app/Filament/Resources` already (`AdminPanelProvider::discoverResources`); there is nothing to register.

- [ ] **Step 1: Add the test helpers**

Append to `tests/Pest.php`, with `use App\Models\Company;`, `use App\Models\User;`, `use Filament\Facades\Filament;` and `use Livewire\Livewire;` added to its imports:

```php
/**
 * A user linked to every company passed in.
 */
function memberOf(Company ...$companies): User
{
    $user = User::factory()->create();
    $user->companies()->attach(array_map(fn (Company $company): string => $company->getKey(), $companies));

    return $user;
}

/**
 * Signs in a member of $company and makes it the current tenant, for Livewire
 * tests of company-scoped pages.
 *
 * The panel is booted too, because that is when Filament registers the tenant
 * scope on Customer. Without the boot a component test runs unscoped — and
 * passes against exactly the leak it exists to catch.
 */
function actInCompany(Company $company): User
{
    $user = memberOf($company);

    // Livewire::actingAs() authenticates immediately; it has to run before
    // Filament::setTenant(), whose event requires an authenticated user.
    Livewire::actingAs($user);

    Filament::setCurrentPanel('admin');
    Filament::setTenant($company);
    Filament::bootCurrentPanel();

    return $user;
}
```

- [ ] **Step 2: Write the failing routing tests**

Create `tests/Feature/CustomerRoutingTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Company;
use App\Models\Customer;
use Tests\TestCase;

it('resolves a customer number within the company in the url', function (): void {
    /** @var TestCase $this */
    // Both companies have a K-0001. A lookup that ignores the tenant finds
    // whichever row comes first and fails one of the two halves; a test in
    // which only one company had a K-0001 would pass against exactly that bug.
    $alpha = Company::factory()->create(['name' => 'Alpha GmbH']);
    $beta = Company::factory()->create(['name' => 'Beta GmbH']);
    Customer::factory()->for($alpha)->create(['name' => 'Kunde von Alpha']);
    Customer::factory()->for($beta)->create(['name' => 'Kunde von Beta']);

    $this->actingAs(memberOf($alpha, $beta));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Kunde von Alpha')
        ->assertDontSee('Kunde von Beta');

    $this->get('/admin/beta-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Kunde von Beta')
        ->assertDontSee('Kunde von Alpha');
});

it('does not find a number that exists only in another company', function (): void {
    /** @var TestCase $this */
    $alpha = Company::factory()->create(['name' => 'Alpha GmbH']);
    $beta = Company::factory()->create(['name' => 'Beta GmbH']);
    Customer::factory()->for($alpha)->create();
    Customer::factory()->for($beta)->count(2)->create();

    $this->actingAs(memberOf($alpha, $beta))
        ->get('/admin/alpha-gmbh/customers/K-0002')
        ->assertNotFound();
});

it('keeps a user out of the customers of a company they do not belong to', function (): void {
    /** @var TestCase $this */
    $mine = Company::factory()->create(['name' => 'Mine GmbH']);
    $theirs = Company::factory()->create(['name' => 'Theirs GmbH']);
    Customer::factory()->for($theirs)->create();

    $this->actingAs(memberOf($mine))
        ->get('/admin/theirs-gmbh/customers/K-0001')
        ->assertNotFound();
});

it('generates customer urls from the customer number', function (): void {
    // Fails if the route key is still the UUID — the page would work when
    // typed by hand while every generated link pointed somewhere else.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $customer = Customer::factory()->for($company)->create();

    expect(CustomerResource::getUrl('view', ['record' => $customer], tenant: $company))
        ->toEndWith('/admin/alpha-gmbh/customers/K-0001');
});

it('answers a malformed or foreign key in the url with a 404', function (string $key): void {
    /** @var TestCase $this */
    // A 404, not a 500: an eleven-digit number would overflow the integer
    // column if it reached the query. The UUID must not open the customer.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $customer = Customer::factory()->for($company)->create();

    $key = $key === 'uuid' ? $customer->getKey() : $key;

    $this->actingAs(memberOf($company))
        ->get("/admin/alpha-gmbh/customers/{$key}")
        ->assertNotFound();
})->with(['K-abc', 'K-99999999999', 'k-0001', '0001', 'uuid']);

it('shows a business customer with its contact person and a private person without', function (): void {
    /** @var TestCase $this */
    // Both directions: a view that always or never renders the business-only
    // entries fails one half.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create([
        'name' => 'Bauer & Kollegen GmbH',
        'contact_person' => 'Sofia Kraus',
        'street' => 'Leopoldstraße 12',
    ]);
    Customer::factory()->for($company)->privatePerson()->create(['name' => 'Dr. Annika Vogel']);

    $this->actingAs(memberOf($company));

    $this->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Bauer & Kollegen GmbH')
        ->assertSee('K-0001')
        ->assertSee('Leopoldstraße 12')
        ->assertSee('Sofia Kraus')
        ->assertSee('Ansprechpartner');

    $this->get('/admin/alpha-gmbh/customers/K-0002')
        ->assertOk()
        ->assertSee('Dr. Annika Vogel')
        ->assertSee('Privatperson')
        ->assertDontSee('Ansprechpartner');
});

it('lists Kunden in the sidebar of a company', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);

    $this->actingAs(memberOf($company))
        ->get('/admin/alpha-gmbh')
        ->assertOk()
        ->assertSee('/admin/alpha-gmbh/customers', escape: false)
        ->assertSee('Kunden');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CustomerRoutingTest.php`
Expected: FAIL — `Class "App\Filament\Resources\Customers\CustomerResource" not found`, and 404s where 200s are expected.

- [ ] **Step 4: Write the resource, the pages, the infolist and a minimal table**

Create `app/Filament/Resources/Customers/CustomerResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers;

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\Schemas\CustomerInfolist;
use App\Filament\Resources\Customers\Tables\CustomersTable;
use App\Models\Customer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * A company's customers, at /admin/{company}/customers.
 *
 * Tenant-aware, like every resource in this panel: Filament scopes the list
 * query and the URL lookup of a record to the company in the path, and a
 * customer of another company is a 404. Owned through Customer::company(),
 * which is the ownership relationship name Filament derives from Company.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    // Above Firmendaten, which the panel provider registers at sort 2.
    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    // A title attribute would otherwise switch on the panel's global search
    // box — an affordance no task here needs.
    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('customer.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('customer.plural_label');
    }

    public static function infolist(Schema $schema): Schema
    {
        return CustomerInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'view' => ViewCustomer::route('/{record}'),
        ];
    }
}
```

Create `app/Filament/Resources/Customers/Pages/ListCustomers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;
}
```

Create `app/Filament/Resources/Customers/Pages/ViewCustomer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only today, and largely a repeat of the form. It exists because the
 * invoicing wave adds the customer's documents here, and because the list's
 * Öffnen should lead somewhere that cannot be changed by accident.
 */
class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string
    {
        return $this->customer()->name;
    }

    protected function customer(): Customer
    {
        $record = $this->getRecord();

        assert($record instanceof Customer);

        return $record;
    }
}
```

Create `app/Filament/Resources/Customers/Schemas/CustomerInfolist.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('customer.sections.customer'))->schema([
                TextEntry::make('number')
                    ->label(__('customer.fields.number'))
                    ->formatStateUsing(fn (int $state): string => Customer::formatNumber($state)),
                TextEntry::make('type')
                    ->label(__('customer.fields.type'))
                    ->badge(),
                TextEntry::make('name')
                    ->label(__('customer.fields.name')),
                TextEntry::make('contact_person')
                    ->label(__('customer.fields.contact_person'))
                    ->placeholder('—')
                    ->visible(fn (Customer $record): bool => $record->type->isBusiness()),
                TextEntry::make('vat_id')
                    ->label(__('customer.fields.vat_id'))
                    ->placeholder('—')
                    ->visible(fn (Customer $record): bool => $record->type->isBusiness()),
            ]),

            Section::make(__('customer.sections.address'))->schema([
                TextEntry::make('street')->label(__('customer.fields.street')),
                TextEntry::make('postal_code')->label(__('customer.fields.postal_code')),
                TextEntry::make('city')->label(__('customer.fields.city')),
            ]),

            Section::make(__('customer.sections.contact'))->schema([
                TextEntry::make('email')
                    ->label(__('customer.fields.email'))
                    ->placeholder('—'),
            ]),
        ]);
    }
}
```

Create `app/Filament/Resources/Customers/Tables/CustomersTable.php` (minimal; Task 4 replaces the whole file):

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('customer.fields.name')),
        ]);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CustomerRoutingTest.php`
Expected: PASS.

Then confirm the scoping test can fail: temporarily add `protected static bool $isScopedToTenant = false;` to `CustomerResource`, rerun, see "resolves a customer number within the company in the url" and "does not find a number that exists only in another company" fail, and remove the line.

- [ ] **Step 6: Run the checks and commit**

Run the checks. Expected: all green.

```bash
git add app/Filament/Resources/Customers tests/Pest.php tests/Feature/CustomerRoutingTest.php
git -c user.name="$(git log -1 --format=%an)" -c user.email="$(git log -1 --format=%ae)" commit -m "feat: open a customer at its customer number

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Create and edit

**Files:**
- Create: `app/Filament/Resources/Customers/Schemas/CustomerForm.php`
- Create: `app/Filament/Resources/Customers/Pages/CreateCustomer.php`
- Create: `app/Filament/Resources/Customers/Pages/EditCustomer.php`
- Modify: `app/Filament/Resources/Customers/CustomerResource.php` (add `form()`, pages `create` and `edit`)
- Modify: `app/Filament/Resources/Customers/Pages/ViewCustomer.php` (add Bearbeiten header action)
- Test: `tests/Feature/CustomerFormTest.php`

**Interfaces:**
- Consumes: `Customer`, `CustomerType::fromFormState()`, `CustomerType::isBusiness()` (Task 1); `CustomerResource`, `ViewCustomer`, `actInCompany()` (Task 2).
- Produces:
  - `CustomerResource` pages `create` (`/customers/create`) and `edit` (`/customers/{record}/edit`).
  - `App\Filament\Resources\Customers\Pages\CreateCustomer` — Livewire action `create`.
  - `App\Filament\Resources\Customers\Pages\EditCustomer` — Livewire action `save`.
  - Form field names: `type`, `name`, `contact_person`, `vat_id`, `street`, `postal_code`, `city`, `email`, and on edit a disabled `number`.
  - Task 4's "Neuer Kunde" action links to `CustomerResource::getUrl('create')`.

- [ ] **Step 1: Write the failing form tests**

Create `tests/Feature/CustomerFormTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\CustomerType;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Company;
use App\Models\Customer;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * @return array<string, string>
 */
function validCustomer(): array
{
    return [
        'type' => CustomerType::Business->value,
        'name' => 'Bauer & Kollegen GmbH',
        'street' => 'Leopoldstraße 12',
        'postal_code' => '80802',
        'city' => 'München',
        'email' => 'rechnung@bauer-kollegen.de',
    ];
}

it('creates the customer in the current company with its next number', function (): void {
    // Beta already has three customers. A customer saved without the tenant,
    // or numbered across companies, would come out as Beta's or as number 4.
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    Customer::factory()->for($beta)->count(3)->create();

    actInCompany($alpha);

    Livewire::test(CreateCustomer::class)
        ->fillForm(validCustomer())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect(CustomerResource::getUrl('view', ['record' => 'K-0001']));

    // Unscoped, so the assertion is about the row and not about what the
    // tenant scope lets this query see.
    $customer = Customer::query()->withoutGlobalScopes()
        ->where('name', 'Bauer & Kollegen GmbH')
        ->sole();

    expect($customer->company_id)->toBe($alpha->getKey())
        ->and($customer->number)->toBe(1);
});

it('ignores a customer number smuggled into the create form', function (): void {
    // Fails if the number field is dehydrated on create or `number` becomes
    // fillable and the hook stops overwriting it.
    $company = Company::factory()->create();
    actInCompany($company);

    Livewire::test(CreateCustomer::class)
        ->fillForm(validCustomer())
        ->set('data.number', 99)
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Customer::query()->withoutGlobalScopes()->sole()->number)->toBe(1);
});

it('requires the name and the address, and nothing else', function (): void {
    // Both directions in one submission: the required fields error and the
    // optional ones do not. Required-everything and required-nothing each
    // fail one half.
    actInCompany(Company::factory()->create());

    Livewire::test(CreateCustomer::class)
        ->fillForm([
            'type' => CustomerType::Business->value,
            'name' => '',
            'contact_person' => '',
            'vat_id' => '',
            'street' => '',
            'postal_code' => '',
            'city' => '',
            'email' => '',
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'street' => 'required',
            'postal_code' => 'required',
            'city' => 'required',
        ])
        ->assertHasNoFormErrors(['contact_person', 'vat_id', 'email']);
});

it('rejects an email that is not an address', function (): void {
    actInCompany(Company::factory()->create());

    Livewire::test(CreateCustomer::class)
        ->fillForm([...validCustomer(), 'email' => 'keine-adresse'])
        ->call('create')
        ->assertHasFormErrors(['email' => 'email']);
});

it('shows contact person and vat id for a Firma only', function (): void {
    // Both directions: always-visible and never-visible each fail one half.
    actInCompany(Company::factory()->create());

    Livewire::test(CreateCustomer::class)
        ->fillForm(['type' => CustomerType::PrivatePerson->value])
        ->assertFormFieldHidden('contact_person')
        ->assertFormFieldHidden('vat_id')
        ->fillForm(['type' => CustomerType::Business->value])
        ->assertFormFieldVisible('contact_person')
        ->assertFormFieldVisible('vat_id');
});

it('round-trips umlauts and ampersands through the form', function (): void {
    /** @var TestCase $this */
    // German names and streets are the normal case. Checked in the database
    // and on the rendered view page, where the ampersand must arrive escaped
    // once.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $user = actInCompany($company);

    Livewire::test(CreateCustomer::class)
        ->fillForm([...validCustomer(), 'name' => 'Ölmühle Müller & Söhne', 'street' => 'Grünstraße 7'])
        ->call('create')
        ->assertHasNoFormErrors();

    $customer = Customer::query()->withoutGlobalScopes()->sole();

    expect($customer->name)->toBe('Ölmühle Müller & Söhne')
        ->and($customer->street)->toBe('Grünstraße 7');

    $this->actingAs($user)
        ->get('/admin/alpha-gmbh/customers/K-0001')
        ->assertOk()
        ->assertSee('Ölmühle Müller & Söhne')
        ->assertDontSee('Ã', escape: false);
});

it('shows the number read-only on edit and not at all on create', function (): void {
    $company = Company::factory()->create();
    Customer::factory()->for($company)->count(2)->create();
    actInCompany($company);

    Livewire::test(EditCustomer::class, ['record' => 'K-0002'])
        ->assertFormSet(['number' => 'K-0002'])
        ->assertFormFieldIsDisabled('number');

    Livewire::test(CreateCustomer::class)
        ->assertFormFieldHidden('number');
});

it('keeps number and deactivation when a deactivated customer is edited', function (): void {
    // The save path must not renumber the customer or clear archived_at — the
    // form knows neither field as writable.
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->archived()->create();
    Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(EditCustomer::class, ['record' => 'K-0001'])
        ->fillForm(['city' => 'Regensburg'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(CustomerResource::getUrl('view', ['record' => 'K-0001']));

    $customer->refresh();

    expect($customer->city)->toBe('Regensburg')
        ->and($customer->number)->toBe(1)
        ->and($customer->isArchived())->toBeTrue();
});

it('clears contact person and vat id when the form switches a Firma to a Privatperson', function (): void {
    // End to end through the form, because a hidden field is exactly what the
    // form does not send.
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create([
        'contact_person' => 'Sofia Kraus',
        'vat_id' => 'DE123456789',
    ]);
    actInCompany($company);

    Livewire::test(EditCustomer::class, ['record' => 'K-0001'])
        ->fillForm(['type' => CustomerType::PrivatePerson->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $customer->refresh();

    expect($customer->contact_person)->toBeNull()
        ->and($customer->vat_id)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CustomerFormTest.php`
Expected: FAIL — `Class "App\Filament\Resources\Customers\Pages\CreateCustomer" not found`.

- [ ] **Step 3: Write the form and the two pages**

Create `app/Filament/Resources/Customers/Schemas/CustomerForm.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerType;
use App\Models\Customer;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('customer.sections.customer'))->schema([
                TextInput::make('number')
                    ->label(__('customer.fields.number'))
                    ->formatStateUsing(fn (?int $state): ?string => $state === null ? null : Customer::formatNumber($state))
                    // Shown so the owner can see it, never written: the
                    // number is assigned on creation and does not change.
                    // Absent on create, where it does not exist yet.
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                ToggleButtons::make('type')
                    ->label(__('customer.fields.type'))
                    ->options(CustomerType::class)
                    ->default(CustomerType::Business)
                    ->inline()
                    ->required()
                    // Contact person and VAT ID appear and disappear with
                    // this, so the form re-renders on change.
                    ->live(),
                TextInput::make('name')
                    ->label(__('customer.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('contact_person')
                    ->label(__('customer.fields.contact_person'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => self::isBusiness($get)),
                TextInput::make('vat_id')
                    ->label(__('customer.fields.vat_id'))
                    ->maxLength(50)
                    ->visible(fn (Get $get): bool => self::isBusiness($get)),
            ]),

            Section::make(__('customer.sections.address'))->schema([
                TextInput::make('street')
                    ->label(__('customer.fields.street'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('postal_code')
                    ->label(__('customer.fields.postal_code'))
                    ->required()
                    ->maxLength(10),
                TextInput::make('city')
                    ->label(__('customer.fields.city'))
                    ->required()
                    ->maxLength(255),
            ]),

            Section::make(__('customer.sections.contact'))->schema([
                TextInput::make('email')
                    ->label(__('customer.fields.email'))
                    ->email()
                    ->maxLength(255),
            ]),
        ]);
    }

    /**
     * The same predicate the model clears the fields by on save, so the form
     * cannot render a field that the save then throws away, or hide one that
     * the save keeps.
     */
    private static function isBusiness(Get $get): bool
    {
        return CustomerType::fromFormState($get('type'))?->isBusiness() ?? false;
    }
}
```

Create `app/Filament/Resources/Customers/Pages/CreateCustomer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Company;
use App\Models\Customer;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomer extends CreateRecord
{
    protected static string $resource = CustomerResource::class;

    // One obvious primary action; "create and create another" is a second.
    protected static bool $canCreateAnother = false;

    public function getTitle(): string
    {
        return __('customer.actions.create');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $customer = new Customer($data);

        // Filament associates the tenant in a `creating` listener of its own.
        // Whether that runs before or after Customer's — which numbers the
        // customer within its company, and so needs the company — depends on
        // which listener was registered first. Associating here, before
        // saving, makes the order irrelevant.
        /** @var Company $company */
        $company = Filament::getTenant();

        $customer->company()->associate($company);
        $customer->save();

        return $customer;
    }
}
```

Create `app/Filament/Resources/Customers/Pages/EditCustomer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Resources\Pages\EditRecord;

/**
 * No header actions: there is no delete (customers are deactivated, never
 * deleted), and deactivation lives on the view page and the list.
 */
class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
```

In `CustomerResource.php`, add the imports `use App\Filament\Resources\Customers\Pages\CreateCustomer;`, `use App\Filament\Resources\Customers\Pages\EditCustomer;` and `use App\Filament\Resources\Customers\Schemas\CustomerForm;`, add:

```php
    public static function form(Schema $schema): Schema
    {
        return CustomerForm::configure($schema);
    }
```

and replace `getPages()` with:

```php
    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }
```

`create` must stay above `view` so that `/customers/create` is not read as a record key.

In `ViewCustomer.php`, add `use Filament\Actions\EditAction;` and:

```php
    /**
     * @return array<\Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label(__('customer.actions.edit')),
        ];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CustomerFormTest.php tests/Feature/CustomerRoutingTest.php`
Expected: PASS.

Then confirm the association test can fail: temporarily delete the `associate()` line from `CreateCustomer::handleRecordCreation()`, rerun `tests/Feature/CustomerFormTest.php`, and see "creates the customer in the current company" fail (with the model's `LogicException` or a wrong company); restore the line.

- [ ] **Step 5: Run the checks and commit**

Run the checks. Expected: all green.

```bash
git add app/Filament/Resources/Customers tests/Feature/CustomerFormTest.php
git -c user.name="$(git log -1 --format=%an)" -c user.email="$(git log -1 --format=%ae)" commit -m "feat: create and edit customers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The list, search and deactivation

**Files:**
- Create: `app/Filament/Resources/Customers/Actions/CustomerActions.php`
- Replace: `app/Filament/Resources/Customers/Tables/CustomersTable.php`
- Modify: `app/Filament/Resources/Customers/CustomerResource.php` (add `nameWithStatus()`)
- Modify: `app/Filament/Resources/Customers/Pages/ListCustomers.php` (header action)
- Modify: `app/Filament/Resources/Customers/Pages/ViewCustomer.php` (heading, deactivate/reactivate)
- Test: `tests/Feature/CustomerListTest.php`

**Interfaces:**
- Consumes: `Customer::formatNumber()`, `Customer::numberFromSearch()`, `archive()`, `unarchive()`, `isArchived()` (Task 1); `CustomerResource`, `ViewCustomer::customer()`, `actInCompany()`, `memberOf()` (Task 2); the `create` page (Task 3).
- Produces:
  - `CustomerActions::archive(): Action` (name `archive`) and `CustomerActions::unarchive(): Action` (name `unarchive`).
  - `CustomersTable::applySearch(Builder $query, string $search): void`.
  - `CustomerResource::nameWithStatus(Customer $customer): HtmlString`.
  - List header action named `create`; empty-state action named `createFirst`.

- [ ] **Step 1: Write the failing list tests**

Create `tests/Feature/CustomerListTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Models\Company;
use App\Models\Customer;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Livewire\Livewire;
use Tests\TestCase;

it('lists only the customers of the company in the url', function (): void {
    /** @var TestCase $this */
    $alpha = Company::factory()->create(['name' => 'Alpha GmbH']);
    $beta = Company::factory()->create(['name' => 'Beta GmbH']);
    Customer::factory()->for($alpha)->create(['name' => 'Kunde von Alpha']);
    Customer::factory()->for($beta)->create(['name' => 'Kunde von Beta']);

    $this->actingAs(memberOf($alpha, $beta))
        ->get('/admin/alpha-gmbh/customers')
        ->assertOk()
        ->assertSee('Kunde von Alpha')
        ->assertDontSee('Kunde von Beta');
});

it('sorts by name, not by number', function (): void {
    // Created out of alphabetical order, so number order and name order differ.
    $company = Company::factory()->create();
    $weber = Customer::factory()->for($company)->create(['name' => 'Weber Haustechnik e.K.']);
    $bauer = Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);
    $lindner = Customer::factory()->for($company)->create(['name' => 'Lindner Consulting GmbH']);
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->assertCanSeeTableRecords([$bauer, $lindner, $weber], inOrder: true);
});

it('finds a customer by its number however it is typed, and only that one', function (string $search): void {
    // Customer 14 is there to catch a substring match on "4". Names, emails
    // and cities carry no digits, so only the number can match.
    $company = Company::factory()->create();
    $customers = Customer::factory()->for($company)->count(14)
        ->sequence(fn (Sequence $sequence): array => [
            'name' => 'Kunde '.str_repeat('x', $sequence->index + 1),
            'email' => null,
            'city' => 'Landshut',
        ])
        ->create();
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$customers[3]])
        ->assertCanNotSeeTableRecords([$customers[13]]);
})->with(['K-0004', 'k-0004', '0004', '4', ' K-0004 ']);

it('finds customers by name, email and city, case-insensitively', function (string $search): void {
    $company = Company::factory()->create();
    $bauer = Customer::factory()->for($company)->create([
        'name' => 'Bauer & Kollegen GmbH',
        'email' => 'rechnung@bauer-kollegen.de',
        'city' => 'München',
    ]);
    $other = Customer::factory()->for($company)->create([
        'name' => 'Lindner Consulting GmbH',
        'email' => 'info@lindner.de',
        'city' => 'Nürnberg',
    ]);
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->searchTable($search)
        ->assertCanSeeTableRecords([$bauer])
        ->assertCanNotSeeTableRecords([$other]);
})->with(['bauer', 'RECHNUNG@', 'münchen']);

it('keeps a search inside the company', function (): void {
    // Beta's customer matches "bau" by city only. A search that ORs its
    // conditions onto the tenant scope without grouping them reads
    // "company = alpha AND name ILIKE x OR city ILIKE x" and lists it.
    $alpha = Company::factory()->create();
    $beta = Company::factory()->create();
    $mine = Customer::factory()->for($alpha)->create(['name' => 'Bauer GmbH', 'city' => 'Landshut']);
    $theirs = Customer::factory()->for($beta)->create(['name' => 'Weber GmbH', 'city' => 'Baunach']);
    actInCompany($alpha);

    Livewire::test(ListCustomers::class)
        ->searchTable('bau')
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('treats like wildcards in a search literally', function (string $search): void {
    // Unescaped, "%" matches every customer. Emails are cleared because
    // faker's addresses may contain an underscore.
    $company = Company::factory()->create();
    Customer::factory()->for($company)->count(2)->create([
        'name' => 'Bauer GmbH',
        'email' => null,
        'city' => 'Landshut',
    ]);
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->searchTable($search)
        ->assertCountTableRecords(0);
})->with(['%', '_']);

it('marks a deactivated customer in the list and leaves the rest unmarked', function (): void {
    /** @var TestCase $this */
    // Both directions: a badge on every row or on none fails one half.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    Customer::factory()->for($company)->create(['name' => 'Bauer GmbH']);
    $user = memberOf($company);

    $this->actingAs($user)
        ->get('/admin/alpha-gmbh/customers')
        ->assertOk()
        ->assertDontSee('Deaktiviert');

    Customer::factory()->for($company)->archived()->create(['name' => 'Weber Haustechnik e.K.']);

    $this->actingAs($user)
        ->get('/admin/alpha-gmbh/customers')
        ->assertOk()
        ->assertSee('Weber Haustechnik e.K.')
        ->assertSee('Deaktiviert');
});

it('escapes a customer name exactly once in the list and in the heading', function (bool $archived): void {
    /** @var TestCase $this */
    // The name reaches the page through an HtmlString, so the escaping is
    // this code's job: raw would let "<Söhne>" through as markup, and escaping
    // twice would print "&amp;amp;".
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);
    $factory = Customer::factory()->for($company);
    ($archived ? $factory->archived() : $factory)->create(['name' => 'Bauer & <Söhne> GmbH']);
    $user = memberOf($company);

    foreach (['/admin/alpha-gmbh/customers', '/admin/alpha-gmbh/customers/K-0001'] as $url) {
        $this->actingAs($user)
            ->get($url)
            ->assertOk()
            ->assertSee('Bauer & <Söhne> GmbH')
            ->assertDontSee('<Söhne>', escape: false)
            ->assertDontSee('&amp;amp;', escape: false);
    }
})->with(['active' => false, 'deactivated' => true]);

it('invites the first customer when the company has none', function (): void {
    /** @var TestCase $this */
    // The header action is hidden here because the empty state carries the
    // same button; both at once would show it twice.
    $company = Company::factory()->create(['name' => 'Alpha GmbH']);

    $this->actingAs(memberOf($company))
        ->get('/admin/alpha-gmbh/customers')
        ->assertOk()
        ->assertSee('Noch keine Kunden angelegt.')
        ->assertSee('Jede Rechnung geht an einen Kunden. Legen Sie den ersten an.');

    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->assertActionHidden('create')
        ->assertActionVisible(TestAction::make('createFirst')->table());
});

it('does not call a search without matches an empty company', function (): void {
    // Filament shows one empty state for "no rows" and "no matching rows".
    // A typo in the search box must not announce that no customers exist.
    $company = Company::factory()->create();
    Customer::factory()->for($company)->create(['name' => 'Bauer GmbH']);
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->assertActionVisible('create')
        ->searchTable('zzz')
        ->assertSee('Keine Kunden gefunden.')
        ->assertDontSee('Noch keine Kunden angelegt.')
        ->assertActionHidden(TestAction::make('createFirst')->table());
});

it('deactivates and reactivates a customer from its row menu', function (): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(ListCustomers::class)
        ->assertActionHidden(TestAction::make('unarchive')->table($customer))
        ->callAction(TestAction::make('archive')->table($customer));

    expect($customer->fresh()?->isArchived())->toBeTrue();

    Livewire::test(ListCustomers::class)
        ->assertActionHidden(TestAction::make('archive')->table($customer))
        ->callAction(TestAction::make('unarchive')->table($customer));

    expect($customer->fresh()?->isArchived())->toBeFalse();
});

it('deactivates and reactivates a customer from its page', function (): void {
    $company = Company::factory()->create();
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company);

    Livewire::test(ViewCustomer::class, ['record' => 'K-0001'])
        ->callAction('archive')
        ->assertActionHidden('archive')
        ->assertActionVisible('unarchive');

    expect($customer->fresh()?->isArchived())->toBeTrue();

    Livewire::test(ViewCustomer::class, ['record' => 'K-0001'])
        ->assertSee('Deaktiviert')
        ->callAction('unarchive');

    expect($customer->fresh()?->isArchived())->toBeFalse();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CustomerListTest.php`
Expected: FAIL — missing actions (`archive`, `create`, `createFirst`), no search, no badge.

- [ ] **Step 3: Write the shared actions, the table, the name rendering and the page changes**

Create `app/Filament/Resources/Customers/Actions/CustomerActions.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Actions;

use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * Deactivate and reactivate, shared by the list's row menu and the view
 * page's header so the two cannot drift apart.
 *
 * No confirmation: undoing either is one click.
 */
final class CustomerActions
{
    public static function archive(): Action
    {
        return Action::make('archive')
            ->label(__('customer.actions.archive'))
            ->icon(Heroicon::OutlinedArchiveBox)
            ->hidden(fn (Customer $record): bool => $record->isArchived())
            ->action(fn (Customer $record) => $record->archive());
    }

    public static function unarchive(): Action
    {
        return Action::make('unarchive')
            ->label(__('customer.actions.unarchive'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->visible(fn (Customer $record): bool => $record->isArchived())
            ->action(fn (Customer $record) => $record->unarchive());
    }
}
```

Replace `app/Filament/Resources/Customers/Tables/CustomersTable.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Filament\Resources\Customers\Actions\CustomerActions;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Customer;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * No filters, no bulk actions and no created_at column
 * (.ai/guidelines/ui/core.blade.php). Deactivated customers stay in the list,
 * greyed and badged, rather than behind a filter.
 */
class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('customer.fields.number'))
                    ->formatStateUsing(fn (int $state): string => Customer::formatNumber($state))
                    ->color('gray'),
                TextColumn::make('name')
                    ->label(__('customer.fields.name'))
                    ->formatStateUsing(fn (Customer $record): Htmlable => CustomerResource::nameWithStatus($record))
                    ->weight(FontWeight::Medium)
                    ->color(fn (Customer $record): ?string => $record->isArchived() ? 'gray' : null)
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('customer.fields.type'))
                    ->badge(),
                TextColumn::make('email')
                    ->label(__('customer.fields.email'))
                    ->color('gray'),
                TextColumn::make('city')
                    ->label(__('customer.fields.city')),
            ])
            ->defaultSort('name')
            ->searchable()
            ->searchUsing(fn (Builder $query, string $search) => self::applySearch($query, $search))
            ->recordActions([
                ActionGroup::make([
                    ViewAction::make()
                        ->label(__('customer.actions.open'))
                        ->icon(Heroicon::OutlinedArrowTopRightOnSquare),
                    EditAction::make()
                        ->label(__('customer.actions.edit')),
                    CustomerActions::archive(),
                    CustomerActions::unarchive(),
                ]),
            ])
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(fn (HasTable $livewire): string => self::isSearching($livewire)
                ? __('customer.list.no_results')
                : __('customer.list.empty'))
            ->emptyStateDescription(fn (HasTable $livewire): ?string => self::isSearching($livewire)
                ? null
                : __('customer.list.empty_description'))
            ->emptyStateActions([
                Action::make('createFirst')
                    ->label(__('customer.actions.create'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->url(fn (): string => CustomerResource::getUrl('create'))
                    ->hidden(fn (HasTable $livewire): bool => self::isSearching($livewire)),
            ]);
    }

    /**
     * One search box over number, name, email and city.
     *
     * The number matches exactly on the parsed integer — "4" finds customer 4
     * and not 14 — and the text columns as case-insensitive substrings with
     * LIKE wildcards escaped. Every condition sits inside one group: an OR
     * left at the top level would escape the tenant scope Filament adds to the
     * same query, and list another company's customers.
     *
     * @param  Builder<Customer>  $query
     */
    public static function applySearch(Builder $query, string $search): void
    {
        $search = trim($search);
        $number = Customer::numberFromSearch($search);
        $pattern = '%'.addcslashes($search, '\\%_').'%';

        $query->where(function (Builder $query) use ($number, $pattern): void {
            $query->where('name', 'ilike', $pattern)
                ->orWhere('email', 'ilike', $pattern)
                ->orWhere('city', 'ilike', $pattern);

            if ($number !== null) {
                $query->orWhere('number', $number);
            }
        });
    }

    /**
     * Filament shows one empty state for "no customers" and "no customers
     * matching"; this tells them apart.
     */
    private static function isSearching(HasTable $livewire): bool
    {
        return filled($livewire->getTableSearch());
    }
}
```

In `CustomerResource.php`, add the imports `use Illuminate\Support\Facades\Blade;` and `use Illuminate\Support\HtmlString;`, and:

```php
    /**
     * The name, followed by a Deaktiviert badge when it applies. Used by the
     * list's name column and the view page's heading, so the two mark a
     * deactivated customer the same way.
     *
     * The name is escaped here, once, because an HtmlString is rendered as
     * is: "Bauer & <Söhne> GmbH" must print as text, not as markup and not as
     * "&amp;amp;".
     */
    public static function nameWithStatus(Customer $customer): HtmlString
    {
        $name = e($customer->name);

        if (! $customer->isArchived()) {
            return new HtmlString($name);
        }

        $badge = Blade::render(
            '<x-filament::badge color="gray" size="sm">{{ $label }}</x-filament::badge>',
            ['label' => __('customer.status.archived')],
        );

        // Inline style rather than utility classes: Filament's pre-built CSS
        // carries only the classes Filament itself uses, and there is no build
        // step here to add more.
        return new HtmlString(
            '<span style="display: inline-flex; align-items: center; gap: 0.5rem">'.$name.$badge.'</span>'
        );
    }
```

Replace `app/Filament/Resources/Customers/Pages/ListCustomers.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('customer.actions.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => CustomerResource::getUrl('create'))
                // With no customers the empty state carries this button;
                // showing it in the header too would put it on the page twice.
                ->visible(fn (): bool => CustomerResource::getEloquentQuery()->exists()),
        ];
    }
}
```

In `ViewCustomer.php`, add the imports `use App\Filament\Resources\Customers\Actions\CustomerActions;` and `use Illuminate\Contracts\Support\Htmlable;`, add:

```php
    /**
     * The heading carries the Deaktiviert badge; the title stays plain text,
     * because it also ends up in the browser tab.
     */
    public function getHeading(): Htmlable
    {
        return CustomerResource::nameWithStatus($this->customer());
    }
```

and extend `getHeaderActions()` to:

```php
        return [
            EditAction::make()->label(__('customer.actions.edit')),
            CustomerActions::archive(),
            CustomerActions::unarchive(),
        ];
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CustomerListTest.php tests/Feature/CustomerRoutingTest.php tests/Feature/CustomerFormTest.php`
Expected: PASS.

Then confirm the tenant-escape test can fail: temporarily replace the `$query->where(function (…) { … })` wrapper in `applySearch()` with the three conditions called directly on `$query` (`$query->where('name', …)->orWhere('email', …)->orWhere('city', …)`), rerun, and see "keeps a search inside the company" fail. Restore the wrapper.

- [ ] **Step 5: Run the checks and commit**

Run the checks. Expected: all green.

```bash
git add app/Filament/Resources/Customers tests/Feature/CustomerListTest.php
git -c user.name="$(git log -1 --format=%an)" -c user.email="$(git log -1 --format=%ae)" commit -m "feat: list, search and deactivate customers

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Development database, a look in the browser, and the documents this wave falsifies

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`
- Modify: `docs/superpowers/specs/2026-09-23-invoice-system-design.md`
- External: the Outline collection "Invoice"

**Interfaces:**
- Consumes: everything above, working.
- Produces: a development environment in which `/admin/{company}/customers` loads, and documentation that no longer says customers do not exist.

- [ ] **Step 1: Migrate the development database**

The suite runs against `invoice_test` and cannot see that `invoice`, the database behind http://localhost:8080, lacks the new table.

Run: `docker compose run --rm app php artisan migrate`
Then: `docker compose run --rm app php artisan migrate:status`
Expected: `2026_09_25_100000_create_customers_table` listed as `Ran`.

- [ ] **Step 2: Look at the screens**

With the app running (`docker compose up -d`), open http://localhost:8080/admin, enter a company, and compare against `/home/tobot/development/kunden.pdf`:

1. "Kunden" in the sidebar between Dashboard and Firmendaten.
2. The empty state: icon, "Noch keine Kunden angelegt.", the description, one "Neuer Kunde" button, and no header button.
3. Create a Firma and a Privatperson. Switching Typ shows and hides Ansprechpartner and USt-IdNr. Saving lands on the view page at `…/customers/K-0001`.
4. The list: columns Kundennr., Name, Typ (blue Firma, grey Privatperson), E-Mail, Ort; sorted by name; one ⋮ per row with Öffnen / Bearbeiten / Deaktivieren.
5. Deactivate one: its row is greyed, with a Deaktiviert badge beside the name, and the menu offers Wieder aktivieren. The view page heading shows the same badge.
6. Search `k-0001`, a name fragment, and a word that matches nothing ("Keine Kunden gefunden.", no button).

If no browser is available in this environment, say so in the task report and ask the human partner to walk through this list; do not mark this step done on the strength of the tests.

- [ ] **Step 3: Record the feature in `README.md`**

In "What it does today", change the start-page bullet's sentence "Inside a company the sidebar holds its dashboard and its company data (Firmendaten)." to:

```markdown
Inside a company the sidebar holds its dashboard, its customers (Kunden) and
its company data (Firmendaten).
```

Add this bullet after "Each legal form is asked only for what applies to it.":

```markdown
- **Customers, kept per company.** Under Kunden, each company keeps its own
  customers — a Firma, with an optional contact person and USt-IdNr., or a
  Privatperson — with billing address and billing email. Every new customer
  gets its company's next customer number (K-0001, K-0002, …) automatically,
  and that number is its address: `/admin/acme-gmbh/customers/K-0004` always
  opens the same customer. One search box finds customers by number, name,
  email or city. A customer of one company never appears under another.
- **Customers are deactivated, never deleted.** A deactivated customer stays in
  the list, greyed and marked "Deaktiviert", keeps its page and its number, and
  can be reactivated from its ⋮ menu or its page.
```

In "Not built yet", replace "Customers, and the entire document side:" with "The entire document side:".

- [ ] **Step 4: Correct `CLAUDE.md`**

Replace the "Current state" paragraph with:

```markdown
**Current state: companies, the tenancy backbone and customers are in place.** A
company can be created, completed and archived, and every company-scoped screen
sits behind a Filament tenant boundary keyed on the company's slug. Each company
keeps its own customers — listed, created, viewed, edited and deactivated under
`/admin/{company}/customers`. Documents, invoice numbering and money handling do
not exist yet. If you are looking for an `Invoice` model, it has not been
written. What works is the container stack, the test harness, a proven PDF
renderer, and a Filament panel with login, tenancy and customers.
```

Append to the "UUID primary keys on every model" bullet:

```markdown
  Customer URLs are the one place a sequential value sits in a path —
  `/admin/{company}/customers/K-0004` — and that is deliberate: every customer
  under a company's slug is one its viewer may already see, so there is no
  neighbour to walk to, and the number prints on every invoice anyway. The UUID
  is still the key. See §3.4 of the customers spec.
```

Add to "Known gaps":

```markdown
- Tenant scoping of `Customer` is Filament's: a global scope and a creation
  hook registered when the panel boots, active only inside panel requests. Code
  that runs outside the panel — a queued job, a console command, v2's
  recurring-invoice run — sees every company's customers and must scope
  explicitly. Livewire tests must boot the panel for the same reason
  (`actInCompany()` in `tests/Pest.php`).
```

Leave "Where things are written down" as it is: it names only the two top-level specs, and wave specs are reached from them.

- [ ] **Step 5: Correct the system design**

In `docs/superpowers/specs/2026-09-23-invoice-system-design.md`, at the end of §3.1 (after the paragraph ending "…are never used as keys."), add:

```markdown
> **Added 2026-09-25.** Customer URLs carry the customer number rather than the
> UUID — `/admin/{company}/customers/K-0004`. This does not breach the argument
> above. Every customer under a company's slug is one its viewer may already
> see, so there is no neighbour to walk to; and the number prints on every
> invoice the customer receives, so it discloses nothing the document does not.
> The UUID remains the key and every foreign key carries it; the number is a
> routing convenience, like the slug. See
> `docs/superpowers/specs/2026-09-25-customers-design.md` §3.4.
```

In §3.3, after the "Customers." bullet under "Per company, maintained ongoing", add:

```markdown
> **Corrected 2026-09-25.** The customers wave built customers without the
> default payment term: payment terms do not exist yet, and a per-customer
> default arrives with them. Customer numbers are assigned per company on
> creation (`K-0001`, `K-0002`, …), never edited, and may have gaps — only
> invoice numbers must be gapless. See
> `docs/superpowers/specs/2026-09-25-customers-design.md` §3.
```

Add two rows to the §15 table:

```markdown
| Customer number | Assigned per company on creation, never editable; gaps allowed — added 2026-09-25 |
| Customer URL | Carries the customer number (`K-0004`), not the UUID; the UUID stays the key — added 2026-09-25, see §3.1 |
```

- [ ] **Step 6: Correct the Outline collection**

Nothing syncs it. Using the `outline` MCP tools:

1. `list_collections`, find "Invoice", and use its id.
2. Search the collection (`list_documents` with a query) for each of: `customer number`, `Kundennummer`, `/customers`, `payment term`, `Customers`, `Kunden`. Read each hit.
3. Patch every page that mirrors system design §3.1, §3.3 or §15 with the same text as Step 5, using `update_document` with `editMode: "patch"`. Correct any page that says customers do not exist yet.
4. Do not touch pages that mirror dated plans — they are history.

List the pages you changed in the task report.

`AGENTS.md` needs nothing: no file under `.ai/guidelines/` changed.

- [ ] **Step 7: Final verification and commit**

Run the checks. Expected: all green, and the full Pest suite passing, with the test count up by the tests of Tasks 1–4.

```bash
git add README.md CLAUDE.md docs/superpowers/specs/2026-09-23-invoice-system-design.md
git -c user.name="$(git log -1 --format=%an)" -c user.email="$(git log -1 --format=%ae)" commit -m "docs: describe customers, and correct what the wave falsified

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
