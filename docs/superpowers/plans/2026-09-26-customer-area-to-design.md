# Customer Area to Design — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the customer create/edit form and the customer detail page in the code in line with the Penpot mockups, on a branch.

**Architecture:** Presentational change only. No new models, no migrations, no new columns. Everything is built from the Filament schema components the codebase already uses (`Section`, `Grid`, `Text`, `EmptyState`, `TextEntry`) — deliberately no Filament widget, because ordering matters here and the infolist gives exact control over it. `Kunde seit` reads the existing `created_at`.

**Tech Stack:** Laravel 13, Filament 5, PHP 8.5, PostgreSQL. All commands through `docker compose run --rm app …`.

**Spec:** The Penpot mockups at
<http://localhost:9001/#/workspace?team-id=ecabb5db-d930-817b-8008-b1c2bf3ea4b9&file-id=ecabb5db-d930-817b-8008-b1c2e756c0e6&page-id=ecabb5db-d930-817b-8008-b1c2e75730d8> —
boards `Kunden – Liste`, `Kunden – leer (Erstnutzung)`, `Kunde – Neu (Formular)`, `Kunde – Detail`.
Vocabulary: `CONTEXT.md`. UI rules: `.ai/guidelines/ui/core.blade.php`. Prior UI calls: the `invoice-ui-decisions` memory.

---

## Context

The mockups were drawn and then, on 2026-09-26, aligned to the ubiquitous language in `CONTEXT.md` (`Geschäftskunde`/`Privatkunde` instead of `Firma`/`Privatperson`) and restructured: the customer detail page lost its right-hand column in favour of dashboard-style tiles under the master data, and its master-data pairs were stacked label-over-value. The code has not followed. It renders three single-column sections with no helper texts, no placeholders, and a header that carries neither the customer number nor the type.

Two things in the design have no data behind them yet, and both were settled before planning:

- **The invoice-dependent parts are built with empty states.** The three tiles and the invoice card are created now and show `0,00 €` / `Noch keine Rechnungen`, so the layout is laid down once. `Neue Rechnung` is rendered **disabled with a tooltip** rather than omitted.
- **`Zahlungsziel` is not built.** Payment terms are company master data that does not exist; the customers spec correction of 2026-09-25 already records its absence. The mockup keeps showing it as the target state — a **deliberate, recorded divergence**, not drift.

## Global Constraints

- **German only in user-facing strings, and only from `lang/de/customer.php`.** No literal German in PHP. Identifiers stay English (`CLAUDE.md`, "Identifiers are English").
- **Vocabulary is `CONTEXT.md`.** `Geschäftskunde`, `Privatkunde`, `deaktivieren`. Never `Firma` for a customer type, never `Privatperson`, never `archivieren`.
- **No CSS build exists.** Arbitrary Tailwind classes do not work — Filament ships only the classes it uses itself. Use Filament component APIs; inline `style="…"` only inside an `HtmlString`, as `CustomerResource::nameWithStatus()` already does.
- **Row actions live in one ⋮ `ActionGroup`** (`.ai/guidelines/ui/core.blade.php:6-28`).
- **Simplicity over density** — no filter, no column, no badge that a real task does not need.
- **PostgreSQL only, never SQLite**, including in tests.
- **Run Rector before Pint.** Full gate: `rector process` → `pint` → `phpstan analyse --memory-limit=512M` → `pest`. Larastan stays at level 8.
- **No migration in this plan.** `created_at` already exists (`database/migrations/2026_09_25_100000_create_customers_table.php:29`).

## Review Focus

Five things the design implies that no task's happy path exercises. Each has its test named in the owning task.

1. **A `Privatkunde`'s detail page must not contain the string `Ansprechpartner` anywhere** — not as a value, not as an empty labelled slot. `CustomerRoutingTest:80` asserts this literally today, and a two-column card is exactly the shape that leaves an empty label behind. *Task 4.*
2. **A long value must not break the two-column card.** A 40-character billing e-mail and a 60-character company name sit in a half-width column. *Task 4.*
3. **The `Deaktiviert` badge must still appear exactly once, and a name containing `<` and `&` must still be escaped exactly once**, in the list and in the detail heading. `CustomerListTest:112` and `:133` guard this; moving the number and type into the header is the change that could break it. *Task 3.*
4. **`Kunde seit` must render a German date and must not be empty.** `created_at` is never null through the factory or the form, but it is not in `$fillable` and nothing has ever rendered it. *Task 4.*
5. **The zero tiles must not claim data they do not have.** `0,00 €` with `0 Rechnungen` is honest; a blank tile or a `—` reads like a loading failure. *Task 5.*

---

## Preparation

- [ ] **Step 0.1: Branch from an up-to-date main**

```bash
cd /Users/kl3tte/development/inv
git fetch origin && git status -sb          # expect: main, clean, 0/0 with origin/main
git checkout -b feat/customer-area-to-design
```

- [ ] **Step 0.2: Copy this plan into the repository**

The repo keeps dated plans next to the specs. Copy this file to
`docs/superpowers/plans/2026-09-26-customer-area-to-design.md` and commit it:

```bash
git add docs/superpowers/plans/2026-09-26-customer-area-to-design.md
git commit -m "docs: plan the customer area against the mockups"
```

- [ ] **Step 0.3: Confirm the green baseline**

```bash
docker compose run --rm app ./vendor/bin/pest
```

Expected: 200 passed. If not, stop — this plan assumes a green tree.

---

### Task 1: Form sections, layout and copy

The mockup's create form is four cards. The code has three, all single-column, with no helper texts and no placeholders.

**Files:**
- Modify: `app/Filament/Resources/Customers/Schemas/CustomerForm.php:19-74`
- Modify: `lang/de/customer.php:32-48`
- Test: `tests/Feature/CustomerFormTest.php`

**Interfaces:**
- Consumes: `CustomerType::fromFormState()` and `->isBusiness()` (`app/Enums/CustomerType.php`), already used by `CustomerForm::isBusiness()`.
- Produces: no new PHP API. Section keys `customer.sections.{type,master,address,billing}` and helper keys `customer.help.*` that Task 4 reuses for the infolist labels.

- [ ] **Step 1.1: Write the failing test**

Add to `tests/Feature/CustomerFormTest.php`:

```php
it('labels the name field for the chosen customer type', function (): void {
    $company = memberOf($user = User::factory()->create());
    actInCompany($company, $user);

    Livewire::test(CreateCustomer::class)
        ->fillForm(['type' => CustomerType::Business])
        ->assertSee('Firmenname')
        ->fillForm(['type' => CustomerType::PrivatePerson])
        ->assertSee('Name')
        ->assertDontSee('Firmenname');
});
```

- [ ] **Step 1.2: Run it and watch it fail**

```bash
docker compose run --rm app ./vendor/bin/pest --filter="labels the name field"
```

Expected: FAIL — the label is the static `Name` today.

- [ ] **Step 1.3: Add the language keys**

In `lang/de/customer.php`, extend `sections` and add `help` and `placeholders`:

```php
'sections' => [
    'type' => 'Typ',
    'master' => 'Stammdaten',
    'address' => 'Rechnungsanschrift',
    'billing' => 'Rechnungsstellung',
    // keep the existing keys used by the infolist until Task 4 removes them
    'customer' => 'Kunde',
    'contact' => 'Kontakt',
],

'help' => [
    'type' => 'Bestimmt, welche Angaben die Rechnung braucht – und steht auf dem Beleg.',
    'address' => 'Erscheint unverändert auf jeder Rechnung an diesen Kunden.',
    'vat_id' => 'Nur bei Geschäftskunden.',
    'email' => 'Ohne E-Mail bleibt nur der PDF-Download.',
],

'placeholders' => [
    'business_name' => 'z. B. Weber Haustechnik e.K.',
    'private_name' => 'z. B. Sofia Kraus',
    'contact_person' => 'Optional',
    'vat_id' => 'DE…',
    'street' => 'Musterstraße 1',
    'postal_code' => '90402',
    'city' => 'Nürnberg',
    'email' => 'name@firma.de',
],
```

Add to `fields`: `'name_business' => 'Firmenname'` (the existing `'name' => 'Name'` stays and serves the private case).

- [ ] **Step 1.4: Rebuild the form schema**

Replace the body of `CustomerForm::configure()` with four sections. Keep every field **name** unchanged — the form tests address fields by name.

```php
return $schema->components([
    Section::make(__('customer.sections.type'))
        ->description(__('customer.help.type'))
        ->schema([
            ToggleButtons::make('type')
                ->hiddenLabel()
                ->options(CustomerType::class)
                ->default(CustomerType::Business)
                ->inline()
                ->required()
                ->live(),
        ]),

    Section::make(__('customer.sections.master'))
        ->schema([
            TextInput::make('number')
                ->label(__('customer.fields.number'))
                ->formatStateUsing(fn (?string $state) => Customer::formatNumber($state))
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit'),
            TextInput::make('name')
                ->label(fn (Get $get) => self::isBusiness($get)
                    ? __('customer.fields.name_business')
                    : __('customer.fields.name'))
                ->placeholder(fn (Get $get) => self::isBusiness($get)
                    ? __('customer.placeholders.business_name')
                    : __('customer.placeholders.private_name'))
                ->required()
                ->maxLength(255),
            Grid::make(3)->schema([
                TextInput::make('contact_person')
                    ->label(__('customer.fields.contact_person'))
                    ->placeholder(__('customer.placeholders.contact_person'))
                    ->maxLength(255)
                    ->columnSpan(2)
                    ->visible(fn (Get $get) => self::isBusiness($get)),
                TextInput::make('vat_id')
                    ->label(__('customer.fields.vat_id'))
                    ->placeholder(__('customer.placeholders.vat_id'))
                    ->helperText(__('customer.help.vat_id'))
                    ->maxLength(50)
                    ->visible(fn (Get $get) => self::isBusiness($get)),
            ]),
        ]),

    Section::make(__('customer.sections.address'))
        ->description(__('customer.help.address'))
        ->schema([
            TextInput::make('street')
                ->label(__('customer.fields.street'))
                ->placeholder(__('customer.placeholders.street'))
                ->required()
                ->maxLength(255),
            Grid::make(4)->schema([
                TextInput::make('postal_code')
                    ->label(__('customer.fields.postal_code'))
                    ->placeholder(__('customer.placeholders.postal_code'))
                    ->required()
                    ->maxLength(10),
                TextInput::make('city')
                    ->label(__('customer.fields.city'))
                    ->placeholder(__('customer.placeholders.city'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpan(3),
            ]),
        ]),

    Section::make(__('customer.sections.billing'))
        ->schema([
            TextInput::make('email')
                ->label(__('customer.fields.email'))
                ->placeholder(__('customer.placeholders.email'))
                ->helperText(__('customer.help.email'))
                ->email()
                ->maxLength(255),
        ]),
]);
```

Add the imports this needs: `Filament\Schemas\Components\Grid`, `App\Models\Customer`. `ToggleButtons`, `TextInput`, `Section`, `Get` are already imported.

> **Zahlungsziel is deliberately absent** from the billing section. Do not add it. See Context.

- [ ] **Step 1.5: Run the whole form suite**

```bash
docker compose run --rm app ./vendor/bin/pest --filter=CustomerFormTest
```

Expected: 11 passed — the 10 existing tests plus the new one. If `shows contact person and vat id for a Geschäftskunde only` fails, the `Grid` wrapper swallowed the per-field `visible()`; keep `visible()` on the fields, not on the Grid.

- [ ] **Step 1.6: Commit**

```bash
git add app/Filament/Resources/Customers/Schemas/CustomerForm.php lang/de/customer.php tests/Feature/CustomerFormTest.php
git commit -m "feat: lay out the customer form as four cards, with the mockup's copy"
```

---

### Task 2: Create page subtitle and button labels

The mockup's create page carries the subtitle `Die Kundennummer wird beim Speichern vergeben.` and a primary button reading `Kunde speichern`. Filament's defaults are no subtitle and `Erstellen`.

**Files:**
- Modify: `app/Filament/Resources/Customers/Pages/CreateCustomer.php:23-26`
- Modify: `lang/de/customer.php`
- Test: `tests/Feature/CustomerFormTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `customer.create.subheading` and `customer.actions.save` keys.

- [ ] **Step 2.1: Write the failing test**

```php
it('tells the user on the create page that the number comes on save', function (): void {
    $company = memberOf($user = User::factory()->create());
    actInCompany($company, $user);

    Livewire::test(CreateCustomer::class)
        ->assertSee('Die Kundennummer wird beim Speichern vergeben.')
        ->assertSee('Kunde speichern');
});
```

- [ ] **Step 2.2: Run it and watch it fail**

```bash
docker compose run --rm app ./vendor/bin/pest --filter="number comes on save"
```

Expected: FAIL on the subheading — it does not exist.

- [ ] **Step 2.3: Add the keys**

```php
'create' => [
    'subheading' => 'Die Kundennummer wird beim Speichern vergeben.',
],
```

and in `actions`: `'save' => 'Kunde speichern'`.

- [ ] **Step 2.4: Implement**

In `CreateCustomer`:

```php
public function getSubheading(): ?string
{
    return __('customer.create.subheading');
}

protected function getCreateFormAction(): Action
{
    return parent::getCreateFormAction()->label(__('customer.actions.save'));
}
```

Import `Filament\Actions\Action`.

- [ ] **Step 2.5: Run and commit**

```bash
docker compose run --rm app ./vendor/bin/pest --filter=CustomerFormTest
git add app/Filament/Resources/Customers/Pages/CreateCustomer.php lang/de/customer.php tests/Feature/CustomerFormTest.php
git commit -m "feat: say on the create page when the customer number is assigned"
```

---

### Task 3: Detail page header

The mockup's header is: name + type badge on the title line, `Kundennr. K-0004` as subtitle, then `Bearbeiten`, a disabled `Neue Rechnung`, and a ⋮ group holding deactivate/reactivate. The code has the name (with the `Deaktiviert` badge), no subtitle, no type badge, and the deactivate actions loose in the header.

**Files:**
- Modify: `app/Filament/Resources/Customers/Pages/ViewCustomer.php:25-58`
- Modify: `app/Filament/Resources/Customers/CustomerResource.php:70-85` (`nameWithStatus`)
- Modify: `lang/de/customer.php`
- Test: `tests/Feature/CustomerListTest.php`, `tests/Feature/CustomerRoutingTest.php`

**Interfaces:**
- Consumes: `CustomerResource::nameWithStatus(Customer $record): HtmlString` — unchanged signature.
- Produces: `CustomerResource::nameWithType(Customer $record): HtmlString`, the heading used by `ViewCustomer::getHeading()`. Task 4 does not use it.

- [ ] **Step 3.1: Write the failing test**

Add to `tests/Feature/CustomerRoutingTest.php`:

```php
it('carries the number and the type in the detail header', function (): void {
    $company = memberOf($user = User::factory()->create());
    $customer = Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);
    actInCompany($company, $user);

    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()
        ->assertSee('Kundennr. '.$customer->formattedNumber())
        ->assertSee('Geschäftskunde');
});
```

- [ ] **Step 3.2: Run it and watch it fail**

```bash
docker compose run --rm app ./vendor/bin/pest --filter="carries the number and the type"
```

Expected: FAIL — no subheading exists.

- [ ] **Step 3.3: Add the keys**

```php
'view' => [
    'subheading' => 'Kundennr. :number',
],
'actions' => [
    // …existing…
    'new_invoice' => 'Neue Rechnung',
    'new_invoice_disabled' => 'Rechnungen gibt es noch nicht.',
],
```

- [ ] **Step 3.4: Extend the heading to carry the type badge**

In `CustomerResource`, beside `nameWithStatus()`, add:

```php
/**
 * The detail heading: the name, its type, and — when it applies — the
 * deactivated marker. Built as one HtmlString because Filament renders a
 * heading as a single node; the name is escaped exactly once, by
 * nameWithStatus().
 */
public static function nameWithType(Customer $record): HtmlString
{
    return new HtmlString(
        self::nameWithStatus($record)->toHtml()
        .' <x-filament::badge color="'.$record->type->getColor().'" size="sm">'
        .e($record->type->getLabel())
        .'</x-filament::badge>'
    );
}
```

> Follow whatever rendering `nameWithStatus()` already does for its badge — if it returns a Blade string that Filament compiles, this one must be built the same way. Read `CustomerResource.php:70-85` first and mirror it exactly; do not introduce a second mechanism.

- [ ] **Step 3.5: Rework the header**

In `ViewCustomer`:

```php
public function getHeading(): string|Htmlable
{
    return CustomerResource::nameWithType($this->getRecord());
}

public function getSubheading(): ?string
{
    return __('customer.view.subheading', ['number' => $this->getRecord()->formattedNumber()]);
}

protected function getHeaderActions(): array
{
    return [
        EditAction::make()->label(__('customer.actions.edit')),
        Action::make('newInvoice')
            ->label(__('customer.actions.new_invoice'))
            ->disabled()
            ->tooltip(__('customer.actions.new_invoice_disabled')),
        ActionGroup::make([
            CustomerActions::deactivate(),
            CustomerActions::reactivate(),
        ]),
    ];
}
```

Imports: `Filament\Actions\Action`, `Filament\Actions\ActionGroup`.

- [ ] **Step 3.6: Run the escaping and badge guards**

```bash
docker compose run --rm app ./vendor/bin/pest --filter=CustomerListTest
docker compose run --rm app ./vendor/bin/pest --filter=CustomerRoutingTest
```

Expected: all pass. `escapes a customer name exactly once in the list and in the heading` is the one to watch — it asserts `assertDontSeeHtml('&amp;amp;')` on the detail page, so a second escape of the name fails it.

- [ ] **Step 3.7: Commit**

```bash
git add app/Filament/Resources/Customers/Pages/ViewCustomer.php app/Filament/Resources/Customers/CustomerResource.php lang/de/customer.php tests/Feature/CustomerRoutingTest.php
git commit -m "feat: give the customer header its number, type and action group"
```

---

### Task 4: The Stammdaten card

One card, two columns, every pair stacked label over value. Left: billing address block, `Ansprechpartner`, `Rechnungs-E-Mail`. Right: `USt-IdNr.`, `Kunde seit`. The number and type are gone from the body — Task 3 put them in the header.

**Files:**
- Modify: `app/Filament/Resources/Customers/Schemas/CustomerInfolist.php:16-47` (replaced wholesale)
- Modify: `lang/de/customer.php`
- Test: `tests/Feature/CustomerRoutingTest.php`

**Interfaces:**
- Consumes: `customer.sections.master`, `customer.sections.address` from Task 1.
- Produces: nothing other tasks read.

- [ ] **Step 4.1: Write the failing tests**

Two, because Review Focus items 1, 2 and 4 all land here:

```php
it('shows when the customer was created, in German', function (): void {
    $company = memberOf($user = User::factory()->create());
    $customer = Customer::factory()->for($company)
        ->create(['created_at' => '2025-03-14 09:00:00']);
    actInCompany($company, $user);

    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()
        ->assertSee('Kunde seit')
        ->assertSee('14.03.2025');
});

it('leaves no business-only label behind for a private customer', function (): void {
    $company = memberOf($user = User::factory()->create());
    $customer = Customer::factory()->for($company)->privatePerson()->create();
    actInCompany($company, $user);

    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()
        ->assertDontSee('Ansprechpartner')
        ->assertDontSee('USt-IdNr.');
});
```

- [ ] **Step 4.2: Run them and watch them fail**

```bash
docker compose run --rm app ./vendor/bin/pest --filter="when the customer was created"
```

Expected: FAIL — `Kunde seit` is rendered nowhere.

- [ ] **Step 4.3: Add the keys**

In `fields`: `'created_at' => 'Kunde seit'`, `'billing_address' => 'Rechnungsanschrift'`.

- [ ] **Step 4.4: Rebuild the infolist**

Replace the three sections in `CustomerInfolist::configure()` with one two-column card. The address is one entry with three lines, matching the mockup's block:

```php
return $schema->components([
    Section::make(__('customer.sections.master'))
        ->columns(2)
        ->schema([
            TextEntry::make('billing_address')
                ->label(__('customer.fields.billing_address'))
                ->state(fn (Customer $record) => new HtmlString(
                    e($record->name).'<br>'
                    .e($record->street).'<br>'
                    .e($record->postal_code.' '.$record->city)
                )),
            TextEntry::make('vat_id')
                ->label(__('customer.fields.vat_id'))
                ->placeholder('—')
                ->visible(fn (Customer $record) => $record->type->isBusiness()),
            TextEntry::make('contact_person')
                ->label(__('customer.fields.contact_person'))
                ->placeholder('—')
                ->visible(fn (Customer $record) => $record->type->isBusiness()),
            TextEntry::make('created_at')
                ->label(__('customer.fields.created_at'))
                ->date('d.m.Y'),
            TextEntry::make('email')
                ->label(__('customer.fields.email'))
                ->placeholder('—'),
        ]),
]);
```

> Filament fills a two-column grid **row by row**, so the reading order above produces
> left/right pairs: address ⟷ USt-IdNr., Ansprechpartner ⟷ Kunde seit, E-Mail ⟷ (empty).
> For a `Privatkunde` the two business entries vanish and the remaining three reflow —
> which is correct: there is no empty labelled slot, and that is exactly what Review
> Focus 1 and the existing `CustomerRoutingTest:80` demand.

Imports: `App\Models\Customer`, `Illuminate\Support\HtmlString`.

- [ ] **Step 4.5: Check a long value does not break the card**

Not a unit test — look at it. Create a customer with a 60-character name and a 40-character e-mail, open the page, and confirm nothing is clipped:

```bash
docker compose run --rm app php artisan tinker --execute="
\$c = App\Models\Company::first();
App\Models\Customer::factory()->for(\$c)->create([
  'name' => 'Ingenieurgemeinschaft Hofmann, Weber & Partner mbB Süd',
  'email' => 'rechnungseingang.zentrale@hofmann-weber-partner.de',
]);"
```

Then open `http://localhost:8080/admin/{company}/customers/` and the new customer's page. Delete the test record afterwards.

- [ ] **Step 4.6: Run the suite and commit**

```bash
docker compose run --rm app ./vendor/bin/pest --filter=Customer
git add app/Filament/Resources/Customers/Schemas/CustomerInfolist.php lang/de/customer.php tests/Feature/CustomerRoutingTest.php
git commit -m "feat: show customer master data as one two-column card"
```

---

### Task 5: The three overview tiles

Three cards in a row under the master data — `Umsatz 2026`, `Offene Forderungen`, `Überfällig` — at zero until invoices exist. Built as a `Grid` of `Section`s rather than a Filament widget, because the design requires them **between** the master data and the invoice card, and only the infolist gives that ordering.

**Files:**
- Modify: `app/Filament/Resources/Customers/Schemas/CustomerInfolist.php`
- Modify: `lang/de/customer.php`
- Test: `tests/Feature/CustomerRoutingTest.php`

**Interfaces:**
- Consumes: the `Section` built in Task 4; this appends a sibling.
- Produces: nothing.

- [ ] **Step 5.1: Confirm the text-size API before writing against it**

The codebase has never sized a `Text` component. Check what this Filament version offers:

```bash
docker compose run --rm app ls vendor/filament/support/src/Enums/
docker compose run --rm app grep -rn "function size" vendor/filament/schemas/src/Components/Text.php
```

Use whatever enum that reveals (expected: `Filament\Support\Enums\TextSize`). If `Text` has no `size()`, fall back to `TextEntry::make('…')->state(…)->size(…)->weight(FontWeight::Bold)` — `FontWeight` is already used in `CustomersTable.php`.

- [ ] **Step 5.2: Write the failing test**

```php
it('shows the overview tiles at zero while there are no invoices', function (): void {
    $company = memberOf($user = User::factory()->create());
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company, $user);

    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()
        ->assertSee('Offene Forderungen')
        ->assertSee('Überfällig')
        ->assertSee('0,00 €')
        ->assertSee('0 Rechnungen');
});
```

- [ ] **Step 5.3: Run it and watch it fail**

```bash
docker compose run --rm app ./vendor/bin/pest --filter="overview tiles at zero"
```

Expected: FAIL — no tile exists.

- [ ] **Step 5.4: Add the keys**

```php
'stats' => [
    'revenue' => 'Umsatz :year',
    'revenue_since' => 'seit 01.01.:year',
    'open' => 'Offene Forderungen',
    'overdue' => 'Überfällig',
    'invoice_count' => '{0}0 Rechnungen|{1}1 Rechnung|[2,*]:count Rechnungen',
    'zero' => '0,00 €',
],
```

- [ ] **Step 5.5: Append the tile grid to the infolist**

After the master-data `Section` from Task 4:

```php
Grid::make(['default' => 1, 'md' => 3])->schema([
    self::tile(__('customer.stats.revenue', ['year' => now()->year]),
        __('customer.stats.zero'),
        __('customer.stats.revenue_since', ['year' => now()->year])),
    self::tile(__('customer.stats.open'),
        __('customer.stats.zero'),
        trans_choice('customer.stats.invoice_count', 0)),
    self::tile(__('customer.stats.overdue'),
        __('customer.stats.zero'),
        trans_choice('customer.stats.invoice_count', 0), 'danger'),
]),
```

and the private helper on the same class:

```php
/**
 * One overview tile: label, a large figure, a quiet sub-line. Zero until the
 * invoicing wave lands — see the plan of 2026-09-26. Built from Section and
 * Text rather than a StatsOverviewWidget because the design puts these
 * between the master data and the invoice list, and widgets can only render
 * before or after the whole infolist.
 */
private static function tile(string $label, string $value, string $note, ?string $color = null): Section
{
    return Section::make($label)->schema([
        Text::make($value)->size(TextSize::Large)->weight(FontWeight::Bold)->color($color),
        Text::make($note)->size(TextSize::Small)->color('gray'),
    ]);
}
```

Imports: `Filament\Schemas\Components\Grid`, `Filament\Schemas\Components\Text`, `Filament\Support\Enums\FontWeight`, plus whatever Step 5.1 established for size.

- [ ] **Step 5.6: Look at it**

```bash
open http://localhost:8080/admin
```

Compare against the `Kunde – Detail` board. The tiles should read as three cards in a row, the third one's figure in red. If the figure is not visibly larger than the note, Step 5.1's API guess was wrong — go back to it rather than reaching for CSS, which will not load.

- [ ] **Step 5.7: Run and commit**

```bash
docker compose run --rm app ./vendor/bin/pest --filter=Customer
git add app/Filament/Resources/Customers/Schemas/CustomerInfolist.php lang/de/customer.php tests/Feature/CustomerRoutingTest.php
git commit -m "feat: put three overview tiles on the customer page, at zero for now"
```

---

### Task 6: The invoice card, empty

**Files:**
- Modify: `app/Filament/Resources/Customers/Schemas/CustomerInfolist.php`
- Modify: `lang/de/customer.php`
- Test: `tests/Feature/CustomerRoutingTest.php`

**Interfaces:**
- Consumes: the `Grid` from Task 5; this appends a sibling after it.
- Produces: nothing.

- [ ] **Step 6.1: Write the failing test**

```php
it('says a customer has no invoices yet', function (): void {
    $company = memberOf($user = User::factory()->create());
    $customer = Customer::factory()->for($company)->create();
    actInCompany($company, $user);

    $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()
        ->assertSee('Rechnungen')
        ->assertSee('Noch keine Rechnungen.');
});
```

- [ ] **Step 6.2: Run it and watch it fail**

```bash
docker compose run --rm app ./vendor/bin/pest --filter="no invoices yet"
```

Expected: FAIL on the empty-state line.

- [ ] **Step 6.3: Add the keys**

```php
'invoices' => [
    'heading' => 'Rechnungen',
    'empty' => 'Noch keine Rechnungen.',
],
```

- [ ] **Step 6.4: Append the card**

Reuse the `EmptyState` component the codebase already uses in `Dashboard.php:27-36` and `SelectCompany.php:64-66`:

```php
Section::make(__('customer.invoices.heading'))->schema([
    EmptyState::make(__('customer.invoices.empty'))
        ->icon(Heroicon::OutlinedDocumentText),
]),
```

Imports: `Filament\Schemas\Components\EmptyState`, `Filament\Support\Icons\Heroicon`.

> No `Alle Rechnungen →` link: it would point nowhere. It arrives with the invoicing wave, together with the table it summarises.

- [ ] **Step 6.5: Run and commit**

```bash
docker compose run --rm app ./vendor/bin/pest --filter=Customer
git add app/Filament/Resources/Customers/Schemas/CustomerInfolist.php lang/de/customer.php tests/Feature/CustomerRoutingTest.php
git commit -m "feat: give the customer page an invoice card with an empty state"
```

---

### Task 7: Tidy the language file, close the gate, record the feature

**Files:**
- Modify: `lang/de/customer.php`
- Modify: `README.md` ("What it does today", the customers bullet)

- [ ] **Step 7.1: Remove the language keys nothing reads any more**

Tasks 1 and 4 replaced the section names. Check each remaining key is still referenced:

```bash
for k in customer sections.customer sections.address sections.contact; do
  echo "== $k"; grep -rn "customer.$k" app/ resources/ tests/ | head;
done
```

Delete `sections.customer` and `sections.contact` if nothing references them. Keep `sections.address` — Task 1's form uses it.

- [ ] **Step 7.2: Run the full gate, in this order**

```bash
docker compose run --rm app ./vendor/bin/rector process
docker compose run --rm app ./vendor/bin/pint
docker compose run --rm app ./vendor/bin/phpstan analyse --memory-limit=512M
docker compose run --rm app ./vendor/bin/pest
```

Expected: Rector `[OK]`, Pint `PASS`, PHPStan `No errors`, **207 tests passing** — 200 before, plus one each from Tasks 1, 2, 3, 5 and 6 and two from Task 4. Rector before Pint: the pair only settles in that order.

- [ ] **Step 7.3: Confirm the development database still serves the page**

The suite runs against `invoice_test` and cannot see the development database. No migration was added here, so no `artisan migrate` is needed — but load the page anyway, because a schema-component mistake shows up at render time and not in a unit test:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -L http://localhost:8080/admin
```

Then log in and open a customer's detail page and the create form. Compare both against the Penpot boards side by side.

- [ ] **Step 7.4: Record the feature in README.md**

The customers bullet under "What it does today" describes the list and the numbering. Extend it with what a user can now see on a customer's page: the master data as one card, an overview of revenue, open receivables and overdue amounts — **stating plainly that those three are zero until invoices exist** — and the date the customer was added. Describe the behaviour, not the classes.

- [ ] **Step 7.5: Commit and open the branch for review**

```bash
git add README.md lang/de/customer.php
git commit -m "docs: record what the customer page now shows"
git log --oneline main..HEAD
```

---

## Verification

End to end, after Task 7:

1. **The gate:** Rector, Pint, PHPStan and Pest all green, 207 tests.
2. **The form:** `/admin/{company}/customers/create` shows four cards — Typ, Stammdaten, Rechnungsanschrift, Rechnungsstellung — with the mockup's helper texts and placeholders, the name field labelled `Firmenname` for a `Geschäftskunde` and `Name` for a `Privatkunde`, `Ansprechpartner` and `USt-IdNr.` side by side and hidden for a private customer, and the subtitle about the customer number. No `Zahlungsziel`.
3. **The detail page:** header carries the name, the type badge and `Kundennr. K-…`, with `Bearbeiten`, a disabled `Neue Rechnung` and a ⋮ group. Below: one two-column master-data card with every pair stacked, three tiles at `0,00 €`, an invoice card saying `Noch keine Rechnungen.`
4. **A private customer's page contains the string `Ansprechpartner` nowhere.** `CustomerRoutingTest:80` proves it; confirm by eye too.
5. **The list is unchanged** and its 13 tests still pass — it already matched the design.
6. **Side by side with Penpot:** open the `Kunde – Neu (Formular)` and `Kunde – Detail` boards and the running pages. The only intended difference is `Zahlungsziel`, which the mockup shows and the code does not.

## Known divergences from the mockups, on purpose

| Mockup shows | Code does | Why |
|---|---|---|
| `Zahlungsziel · 14 Tage netto` in the master data and the form | nothing | Payment terms are company master data that does not exist; spec correction of 2026-09-25 |
| `Neue Rechnung` as a live button | disabled, with a tooltip | No invoice route exists yet |
| Tiles with real figures | `0,00 €` | No `Invoice` model |
| `Rechnungen` table with rows and `Alle Rechnungen →` | empty state | Same |
