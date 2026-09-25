# Company Switcher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move company switching into a top-bar switcher that only switches, give company pages a sidebar with Dashboard and Firmendaten, hide the sidebar on `/admin`, move archiving onto the picker, and remove the companies list.

**Architecture:** Filament's company menu is switched off. A Blade dropdown built from Filament's components is drawn in the top bar by render hooks. The sidebar exists only when a tenant is set (`navigation(fn () => tenant !== null)`). The picker page (`SelectCompany`, already served on `/admin`) renders each company as a schema `Section` with a ⋮ `ActionGroup`, and archived companies in a collapsed section. `CompanyResource` is deleted.

**Tech Stack:** Laravel 13, Filament 5.8 (panels, schemas, actions), Livewire, Pest, PostgreSQL 17, Docker Compose. No Node — Filament's pre-built CSS only.

**Spec:** `docs/superpowers/specs/2026-09-25-company-picker-design.md` (revised 2026-09-25; §2–§5 are what this plan implements, §9 lists what it replaces).

## Global Constraints

- Every command runs in the container: `docker compose run --rm app <command>`.
- PostgreSQL only, including tests (`invoice_test`). Tests never touch the development database `invoice`; companies come from `Company::factory()`.
- No Node, no frontend build: only classes in Filament's shipped CSS exist (`public/css/filament/filament/app.css`). Verified available: `fi-hidden`, `lg:fi-hidden`, `fi-tenant-menu*`, `fi-avatar`, `fi-tenant-avatar`. Inline style only where nothing shipped fits.
- No Filament view published or overridden.
- Identifiers English; user-facing strings German in `lang/de/company.php`.
- Table/row actions live in one vertical-ellipsis `ActionGroup`, never loose buttons.
- Larastan level 8. Rector before Pint.
- Before every commit: `docker compose run --rm app sh -c './vendor/bin/rector process && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress && ./vendor/bin/pest'` — all green.
- Commit with `git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit …`, message ending `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Branch `feat/company-picker`.
- Every test answers "what would make this fail?"

## Review Focus

1. **A user who belongs to exactly one company** opens its dropdown: it must not be empty — it lists that company, marked current. Pinned in Task 2.
2. **An archived company opened by its URL** (allowed by `canAccessTenant()`): the switcher shows its name in the trigger but does not list it in the dropdown. Pinned in Task 2.
3. **A forged Livewire call** archiving or restoring another user's company must change nothing. Pinned in Task 3.
4. **Company names with markup** (`Müller & Söhne <b>GmbH</b>`) print escaped in the switcher trigger, dropdown, tile heading link and archived rows. Pinned in Tasks 2 and 3.
5. **Phone width**: switcher reachable on `/admin` and inside a company. Settled by the Task 1 spike, checked by screenshot in Task 5.

---

## File map

| File | Responsibility | Task |
|---|---|---|
| `resources/views/filament/company-switcher.blade.php` | the top-bar switcher (create) | 2 |
| `resources/views/filament/company-picker-menu.blade.php` | sidebar placeholder menu (delete) | 2 |
| `app/Providers/Filament/AdminPanelProvider.php` | tenant menu off, sidebar only in a company, Firmendaten item, switcher hooks | 2 |
| `app/Filament/Pages/Tenancy/SelectCompany.php` | tiles as sections with ⋮, archived section, archive/restore | 3 |
| `resources/views/filament/pages/company-tile.blade.php` | delete (tiles become schema sections) | 3 |
| `app/Filament/Resources/Companies/**` | delete | 4 |
| `lang/de/company.php` | labels added/removed | 2, 3, 4 |
| `tests/Feature/CompanyPickerTest.php` | picker, switcher, sidebar | 2, 3 |
| `tests/Feature/CompanyListTest.php` | delete (coverage moves to the picker) | 4 |
| `README.md`, spec, Outline | docs | 6 |

---

### Task 1: Spike — switcher placement, phones, actions on repeated sections (throwaway)

Only findings are committed, into this plan.

**Files (temporary):** `app/Providers/Filament/AdminPanelProvider.php`, `resources/views/filament/company-switcher.blade.php`, `app/Filament/Pages/Tenancy/SelectCompany.php`.

**Interfaces:**
- Produces: a "Spike findings" section at the end of this plan fixing (a) the hook(s) and wrapper for desktop and phone, (b) how a ⋮ action on a repeated `Section` is declared and how a test calls it (`TestAction::make(...)->schemaComponent(...)`).

- [ ] **Step 1:** `git status --short` — expected clean.

- [ ] **Step 2: Switcher, spike version.** In `AdminPanelProvider::panel()`: replace `->tenantMenuItems([...])`, `->tenantMenu(fn …)`, `->navigation(fn …)` and the `SIDEBAR_START` render hook with:

```php
            ->tenantMenu(false)
            ->navigation(fn (): bool => Filament::getTenant() !== null)
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn (): string => view('filament.company-switcher', ['companies' => SelectCompany::getCompanies(), 'current' => Filament::getTenant(), 'variant' => 'desktop'])->render())
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn (): string => view('filament.company-switcher', ['companies' => SelectCompany::getCompanies(), 'current' => Filament::getTenant(), 'variant' => 'phone'])->render())
```

Create `resources/views/filament/company-switcher.blade.php` with the Task 2 Step 3 view (copy it verbatim).

- [ ] **Step 3: Actions on repeated sections, spike version.** In `SelectCompany::content()`, replace the tile `View::make(...)` mapping with the Task 3 Step 3 `companyTile()` method body (copy verbatim), and add the `archiveAction()` helper from the same step.

- [ ] **Step 4: Throwaway users** (development DB):

```sh
docker compose run --rm app php artisan tinker --execute='
$two = App\Models\User::query()->create(["name" => "Picker Two", "email" => "picker-two@example.test", "password" => "password"]);
foreach (["Demo Alpha GmbH" => "gmbh", "Demo Beta Handel" => "sole_proprietorship", "Grundstücksverwaltungsgesellschaftsbeteiligungsholding mbH" => "gmbh"] as $n => $f) { $two->companies()->attach(App\Models\Company::query()->create(["name" => $n, "legal_form" => $f])); }
$old = App\Models\Company::query()->create(["name" => "Demo Alt GmbH", "legal_form" => "gmbh"]); $old->forceFill(["archived_at" => now()])->save(); $two->companies()->attach($old);
App\Models\User::query()->create(["name" => "Picker None", "email" => "picker-none@example.test", "password" => "password"]);
echo "ok";'
```

- [ ] **Step 5: Screenshots** with the scratchpad `shot.py` (from the previous plan; pages `/admin,/admin/demo-alpha-gmbh`) at 1280 px and a 390 px variant that also opens the switcher (`[data-company-switcher] button` visible at that width) and screenshots it. Read every PNG.

- [ ] **Step 6: Answer.**
  1. Desktop: switcher sits after the brand, vertically centred, light and dark. If `TOPBAR_LOGO_AFTER` is wrong, try `TOPBAR_LOGO_BEFORE`/wrapper changes.
  2. Phone: the `phone` variant (`lg:fi-hidden`, in `TOPBAR_START`) is visible and opens at 390 px; the `desktop` variant is hidden there (its container `.fi-topbar-start` is `display:none` below 64rem). If `TOPBAR_START` places it badly, try `GLOBAL_SEARCH_BEFORE` (right side). If neither works → stop and ask the owner.
  3. Actions: in a scratch Pest test, `Livewire::actingAs($user)->test(SelectCompany::class)->callAction(TestAction::make('archive')->schemaComponent('company-'.$id))` archives the right company; record the exact working call (component key form, whether the ActionGroup needs addressing).

- [ ] **Step 7:** Append `## Spike findings` to this plan; edit Tasks 2–3 where findings differ.

- [ ] **Step 8: Throw away and commit the plan only.**

```sh
git checkout -- app/Providers/Filament/AdminPanelProvider.php app/Filament/Pages/Tenancy/SelectCompany.php
rm -f resources/views/filament/company-switcher.blade.php tests/Feature/SpikeTest.php
git status --short   # only this plan
git add docs/superpowers/plans/2026-09-25-company-switcher.md
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "docs: record the company switcher spike findings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Top-bar switcher; sidebar only inside a company

**Files:**
- Create: `resources/views/filament/company-switcher.blade.php`
- Delete: `resources/views/filament/company-picker-menu.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`, `lang/de/company.php`
- Test: `tests/Feature/CompanyPickerTest.php`

**Interfaces:**
- Consumes: `SelectCompany::getCompanies(): Collection<int, Company>`; `CompanySettings::getRouteName()`.
- Produces: markup attribute `data-company-switcher` (one per variant); sidebar present only with a tenant.

- [ ] **Step 1: Replace the obsolete tests.** In `tests/Feature/CompanyPickerTest.php` delete: `renders /admin in the full panel layout`, `offers the way back to the picker from inside a company`, `shows the placeholder company menu on /admin, listing the companies`, `shows Filament company menu, not the placeholder, inside a company`, `escapes company names in the placeholder menu`, `builds no navigation links on /admin, but does inside a company`. Add a helper at the top of the file (after the `use` lines) and the new tests:

```php
/**
 * The markup of the first switcher instance, so assertions about the dropdown
 * cannot be satisfied by a tile or a heading elsewhere on the page.
 */
function switcherMarkup(string $html): string
{
    expect($html)->toContain('data-company-switcher');

    return (string) str($html)->after('data-company-switcher')->before('</header>');
}

it('shows the switcher in the top bar on /admin, listing the active companies', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Zeta GmbH']));
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));
    Company::factory()->create(['name' => 'Theirs GmbH']);

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin')->assertOk()->getContent());

    expect($switcher)->toContain('Firma wählen')
        ->toContain('href="'.url('/admin/alpha-gmbh').'"')
        ->toContain('href="'.url('/admin/zeta-gmbh').'"');
    expect($switcher)->not->toContain('Gone GmbH');
    expect($switcher)->not->toContain('Theirs GmbH');
    // Only switches: none of the old menu entries.
    expect($switcher)->not->toContain('Neue Firma');
    expect($switcher)->not->toContain('Firmendaten');
});

it('shows the current company in the switcher inside a company, and lists it marked', function (): void {
    /** @var TestCase $this */
    // Review focus 1: with a single company the dropdown still lists it.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/acme-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Acme GmbH')
        ->toContain('href="'.url('/admin/acme-gmbh').'"')
        ->toContain('data-current-company');
});

it('names an archived company opened by URL in the trigger but does not list it', function (): void {
    /** @var TestCase $this */
    // Review focus 2.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Active GmbH']));
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/gone-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Gone GmbH');
    expect($switcher)->not->toContain('href="'.url('/admin/gone-gmbh').'"');
});

it('hides the switcher from a user with no active company', function (): void {
    /** @var TestCase $this */
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk()
        ->assertDontSee('data-company-switcher', escape: false);
});

it('escapes company names in the switcher', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Müller & Söhne <b>GmbH</b>']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin')->assertOk()->getContent());

    expect($switcher)->toContain('Müller &amp; Söhne &lt;b&gt;GmbH&lt;/b&gt;');
    expect($switcher)->not->toContain('<b>GmbH</b>');
});

it('never renders Filament company menu', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    foreach (['/admin', '/admin/acme-gmbh'] as $path) {
        $this->actingAs($user)->get($path)->assertOk()
            ->assertDontSee('fi-sidebar-header-controls', escape: false);
    }
});

it('has no sidebar on /admin, and Dashboard and Firmendaten inside a company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertDontSee('id="fi-main-sidebar"', escape: false);

    $inside = (string) $this->actingAs($user)->get('/admin/acme-gmbh')->assertOk()->getContent();
    $sidebar = (string) str($inside)->after('id="fi-main-sidebar"')->before('</aside>');

    expect($inside)->toContain('id="fi-main-sidebar"');
    expect($sidebar)->toContain('Dashboard')
        ->toContain('Firmendaten')
        ->toContain('href="'.url('/admin/acme-gmbh/settings').'"');
});
```

(If the sidebar's closing tag is not `</aside>`, use the tag Task 1's screenshot run shows; record a ruling.)

- [ ] **Step 2: Run — expect FAIL** (`data-company-switcher` missing; sidebar present on `/admin`; `fi-sidebar-header-controls` present).

`docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyPickerTest.php`

- [ ] **Step 3: The switcher view.** `resources/views/filament/company-switcher.blade.php`:

```blade
{{--
    The company switcher in the top bar. It only switches: its dropdown lists
    the user's active companies and nothing else (company-picker spec §2.1).

    Rendered twice — once after the brand (desktop; its container is hidden
    below 64rem by Filament's CSS) and once at the top-bar start with
    lg:fi-hidden (phones). $variant says which.
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Company> $companies */
    /** @var \App\Models\Company|null $current */
@endphp

@if ($current !== null || $companies->isNotEmpty())
    <div data-company-switcher @class(['lg:fi-hidden' => $variant === 'phone'])>
        <x-filament::dropdown placement="bottom-start" size class="fi-tenant-menu">
            <x-slot name="trigger">
                <button type="button" class="fi-tenant-menu-trigger">
                    @if ($current)
                        <x-filament-panels::avatar.tenant :tenant="$current" />
                    @else
                        {{-- Inline style: the trigger's .fi-icon rule would push a
                             bare icon to the end with margin-inline-start: auto. --}}
                        <span class="fi-avatar fi-tenant-avatar" style="display: flex; align-items: center; justify-content: center; background: color-mix(in oklab, currentColor 8%, transparent)">
                            <x-filament::icon icon="heroicon-o-building-office-2" style="margin: 0" />
                        </span>
                    @endif
                    <span class="fi-tenant-menu-trigger-text">
                        <span class="fi-tenant-menu-trigger-tenant-name">
                            {{ $current?->name ?? __('company.picker.title') }}
                        </span>
                    </span>
                    <x-filament::icon icon="heroicon-m-chevron-down" />
                </button>
            </x-slot>

            @if ($companies->isNotEmpty())
                <x-filament::dropdown.list>
                    @foreach ($companies as $company)
                        @php($isCurrent = $current?->is($company) ?? false)
                        <x-filament::dropdown.list.item
                            tag="a"
                            :href="filament()->getUrl($company)"
                            :image="filament()->getTenantAvatarUrl($company)"
                            :color="$isCurrent ? 'primary' : 'gray'"
                            @if ($isCurrent) data-current-company @endif
                        >
                            {{ $company->name }}
                        </x-filament::dropdown.list.item>
                    @endforeach
                </x-filament::dropdown.list>
            @endif
        </x-filament::dropdown>
    </div>
@endif
```

Delete `resources/views/filament/company-picker-menu.blade.php`.

- [ ] **Step 4: Panel configuration.** In `AdminPanelProvider::panel()`: delete the whole `->tenantMenuItems([...])` call, the `->tenantMenu(fn …)` call, the `->navigation(fn … NavigationBuilder …)` call and the `SIDEBAR_START` `->renderHook(...)` with their comments; add after `->tenantProfile(CompanySettings::class)`:

```php
            // Switching companies happens in the top-bar switcher, which does
            // nothing else (company-picker spec §2.1). Filament's own company
            // menu — with its settings, registration and custom entries — is off.
            ->tenantMenu(false)
            // The sidebar exists only inside a company: every entry belongs to
            // one, and /admin has none (spec §2.3).
            ->navigation(fn (): bool => Filament::getTenant() !== null)
            ->navigationItems([
                NavigationItem::make(fn (): string => __('company.settings.title'))
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => route(CompanySettings::getRouteName(), ['tenant' => Filament::getTenant()]))
                    ->isActiveWhen(fn (): bool => request()->routeIs(CompanySettings::getRouteName()))
                    ->sort(2),
            ])
            // Once after the brand for desktop, once at the top-bar start for
            // phones, where Filament hides the brand area (spike findings).
            ->renderHook(PanelsRenderHook::TOPBAR_LOGO_AFTER, fn (): string => self::switcher('desktop'))
            ->renderHook(PanelsRenderHook::TOPBAR_START, fn (): string => self::switcher('phone'))
```

and the method:

```php
    private static function switcher(string $variant): string
    {
        return view('filament.company-switcher', [
            'companies' => SelectCompany::getCompanies(),
            'current' => Filament::getTenant(),
            'variant' => $variant,
        ])->render();
    }
```

Imports: add `Filament\Navigation\NavigationItem`; remove `Filament\Navigation\MenuItem`, `Filament\Navigation\NavigationBuilder`, and `CompanyResource` if unused. In `lang/de/company.php` remove the `'all' => 'Alle Firmen'` line.

- [ ] **Step 5: Run, full check, commit.** Tests PASS; Global Constraints check green.

```sh
git add -A app lang resources tests
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "feat: switch companies from the top bar; sidebar only inside a company

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Archiving and restoring on the picker

**Files:**
- Modify: `app/Filament/Pages/Tenancy/SelectCompany.php`, `lang/de/company.php`
- Delete: `resources/views/filament/pages/company-tile.blade.php`
- Test: `tests/Feature/CompanyPickerTest.php`

**Interfaces:**
- Consumes: `Company::archive(): void`, `Company::unarchive(): void`, `User::companies(): BelongsToMany`.
- Produces: schema component keys `company-{uuid}` (active tile) and `archived-company-{uuid}` (archived row); action names `archive`, `unarchive`.

- [ ] **Step 1: Tests.** Add (adjust the `callAction` form to Task 1's finding):

```php
it('archives a company from its tile', function (): void {
    $company = Company::factory()->create(['name' => 'Acme GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($company);

    Livewire::actingAs($user)
        ->test(SelectCompany::class)
        ->callAction(TestAction::make('archive')->schemaComponent("company-{$company->getKey()}"));

    expect($company->fresh()?->isArchived())->toBeTrue();
});

it('restores an archived company from the Deaktiviert section', function (): void {
    $company = Company::factory()->archived()->create(['name' => 'Gone GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach($company);

    Livewire::actingAs($user)
        ->test(SelectCompany::class)
        ->callAction(TestAction::make('unarchive')->schemaComponent("archived-company-{$company->getKey()}"));

    expect($company->fresh()?->isArchived())->toBeFalse();
});

it('cannot archive or restore another users company', function (): void {
    // Review focus 3: the actions exist only on components built from the
    // acting user's own companies, so a forged key finds nothing to act on.
    $theirs = Company::factory()->create(['name' => 'Theirs GmbH']);
    $theirsArchived = Company::factory()->archived()->create(['name' => 'Theirs Old GmbH']);
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create());

    $page = Livewire::actingAs($user)->test(SelectCompany::class);

    try {
        $page->callAction(TestAction::make('archive')->schemaComponent("company-{$theirs->getKey()}"));
    } catch (Throwable) {
        // Filament may throw on an unknown component; either way nothing changes.
    }

    try {
        $page->callAction(TestAction::make('unarchive')->schemaComponent("archived-company-{$theirsArchived->getKey()}"));
    } catch (Throwable) {
    }

    expect($theirs->fresh()?->isArchived())->toBeFalse()
        ->and($theirsArchived->fresh()?->isArchived())->toBeTrue();
});

it('shows the Deaktiviert section only when the user has an archived company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()->assertDontSee('Deaktiviert (');

    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee('Deaktiviert (1)')
        ->assertSee('Gone GmbH');
});

it('shows the Deaktiviert section beside the empty state', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee('Noch keine Firma angelegt.')
        ->assertSee('Deaktiviert (1)');
});
```

Imports: `App\Filament\Pages\Tenancy\SelectCompany`, `Filament\Actions\Testing\TestAction`. In `shows the empty state to a user whose companies are all archived`, the archived name now legitimately appears in the Deaktiviert section, so replace `->assertDontSee('Gone GmbH')` with `->assertDontSee('href="'.url('/admin/gone-gmbh').'"', escape: false)` — archived rows carry no link, so any link to it would be a tile. Delete `lets a long single-word company name wrap inside its tile` and replace with:

```php
it('lets a long single-word company name wrap inside its tile', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Grundstücksverwaltungsgesellschaft mbH']));

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertSee('overflow-wrap: anywhere', escape: false);
});
```

- [ ] **Step 2: Run — expect FAIL** (no `archive` action; no Deaktiviert section).

- [ ] **Step 3: Implement.** In `SelectCompany`: replace `content()` and add helpers:

```php
    public function content(Schema $schema): Schema
    {
        $companies = static::getCompanies();

        $main = $companies->isEmpty()
            ? EmptyState::make(__('company.picker.empty'))
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->footer([$this->createCompanyAction()])
            : Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema($companies->map(fn (Company $company): Section => $this->companyTile($company))->all());

        $archived = static::getArchivedCompanies();

        return $schema->components([
            $main,
            ...($archived->isEmpty() ? [] : [
                Section::make(__('company.picker.archived', ['count' => $archived->count()]))
                    ->collapsible()
                    ->collapsed()
                    ->schema($archived->map(fn (Company $company): Flex => Flex::make([
                        Text::make($company->name),
                        Actions::make([
                            Action::make('unarchive')
                                ->label(__('company.actions.unarchive'))
                                ->icon(Heroicon::OutlinedArrowUturnLeft)
                                ->link()
                                ->action(fn () => $company->unarchive()),
                        ])->alignEnd(),
                    ])->key("archived-company-{$company->getKey()}"))->all()),
            ]),
        ]);
    }

    /**
     * A tile: the name links into the company, the legal form sits beneath,
     * and the ⋮ menu carries the archive action. The name — not the whole card
     * — is the link, because a button inside a link is invalid HTML.
     */
    private function companyTile(Company $company): Section
    {
        $url = Filament::getDefaultPanel()->getUrl($company);

        return Section::make(new HtmlString(sprintf(
            '<a href="%s" style="overflow-wrap: anywhere">%s</a>',
            e($url),
            e($company->name),
        )))
            ->description($company->legal_form->getLabel())
            ->headerActions([
                ActionGroup::make([
                    Action::make('archive')
                        ->label(__('company.actions.archive'))
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->requiresConfirmation()
                        ->action(fn () => $company->archive()),
                ]),
            ])
            ->key("company-{$company->getKey()}");
    }

    /**
     * The user's archived companies, for the Deaktiviert section. Through the
     * join table, like every other boundary here.
     *
     * @return Collection<int, Company>
     */
    public static function getArchivedCompanies(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->companies()->whereNotNull('archived_at')->orderBy('name')->get();
    }
```

Imports: `Filament\Actions\ActionGroup`, `Filament\Schemas\Components\Actions`, `Filament\Schemas\Components\Flex`, `Filament\Schemas\Components\Section`, `Filament\Schemas\Components\Text`, `Illuminate\Support\HtmlString`; drop `Filament\Schemas\Components\View`. Delete `resources/views/filament/pages/company-tile.blade.php`. Update the `getCompanies()` docblock ("The same list as the switcher …").

Lang, in `picker`: `'archived' => 'Deaktiviert (:count)',`.

The actions close over `$company` taken from `getCompanies()` / `getArchivedCompanies()`, both built from the acting user's join table — that is the guard of spec §2.5; no ID arrives from the browser.

- [ ] **Step 4: Run, full check, commit.**

```sh
git add -A app lang resources tests
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "feat: archive and restore companies on the picker

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Remove the companies list

**Files:**
- Delete: `app/Filament/Resources/Companies/` (all three files), `tests/Feature/CompanyListTest.php`
- Modify: `lang/de/company.php`, `app/Providers/Filament/AdminPanelProvider.php` (if it still imports `CompanyResource`), `tests/Feature/CompanyPickerTest.php`

- [ ] **Step 1: Tests.** In `CompanyPickerTest.php` delete `answers a global search on /admin without a company` and add:

```php
it('no longer serves the companies list', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin/acme-gmbh/companies')->assertNotFound();
});

it('renders no search box while nothing is searchable', function (): void {
    /** @var TestCase $this */
    // Filament shows the box only while some resource is globally searchable;
    // the companies list was the only one. It returns with customers.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin/acme-gmbh')->assertOk()
        ->assertDontSee('fi-global-search', escape: false);
});
```

- [ ] **Step 2: Run — expect FAIL** (list returns 200; search box rendered).

- [ ] **Step 3: Delete** `app/Filament/Resources/Companies/` and `tests/Feature/CompanyListTest.php` (its archive coverage now lives in Task 3's tests and `CompanyTest`). In `lang/de/company.php` remove `resource`, `list`, and from `actions` the `open` and `manage` keys; keep `archive`, `unarchive`, `create`. Remove any leftover `CompanyResource` import. `grep -rn "CompanyResource\|ListCompanies\|CompaniesTable\|company.actions.manage\|company.list\|company.resource" app tests resources lang` → nothing.

- [ ] **Step 4: Run, full check, commit.**

```sh
git add -A app lang tests
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "feat: remove the companies list; the picker covers it

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Look at it

- [ ] **Step 1:** Throwaway users as in Task 1 Step 4 (recreate if removed).
- [ ] **Step 2:** Screenshots at 1280 and 390 px, light and dark: `/admin` for `picker-two` (tiles, ⋮ open, Deaktiviert expanded, switcher open) and `picker-none`; `/admin/demo-alpha-gmbh` (switcher open, sidebar with Dashboard/Firmendaten); owner `/admin` and `/admin/balt` (view only). Leave ≥60 s between login bursts (Filament throttles 5 logins/minute per IP).
- [ ] **Step 3:** Check against spec §2: switcher placement and contents, current marked; no sidebar on `/admin`; sidebar entries inside; tile link, legal form, ⋮; long word wraps; Deaktiviert collapsed and expanding; phone switcher reachable; dark mode.
- [ ] **Step 4:** Exercise archive → restore once in the browser on a demo company; confirm the tile moves to Deaktiviert and back.
- [ ] **Step 5:** Remove throwaway users and their companies; `select name from companies` shows only `BALT`.

---

### Task 6: Docs

- [ ] **Step 1: README** — replace the "A start page with all your companies" bullet:

```markdown
- **A start page with all your companies, and a switcher in the header.**
  After logging in you land on `/admin`: one tile per active company — its name
  and legal form — and a button to set up a new one. The company switcher next
  to the logo moves you between companies from any screen; the logo itself
  brings you back to the start page. Inside a company the sidebar holds its
  dashboard and its company data (Firmendaten). With no company yet, the start
  page says so and offers to create one — nothing makes you.
```

  and replace the "Companies are deactivated, never deleted" bullet's body with: "An archived company drops out of the switcher but keeps its URL, so everything it is attached to stays readable. Archive and restore from the ⋮ menu on its tile and the "Deaktiviert" section of the start page — including your last active company."

- [ ] **Step 2:** `grep -n -i "company menu\|companies list\|Firmen verwalten\|Alle Firmen" README.md CLAUDE.md docs/superpowers/specs/2026-09-23-invoice-system-design.md` — correct each hit that describes the removed pieces as current (the dated companies-and-tenancy spec is not edited).
- [ ] **Step 3:** Spec: append the spike findings in one paragraph under §4.
- [ ] **Step 4: Outline** "Running It Locally" (`f5e5c024-bd44-4e73-b627-ccee8ac78d01`): patch "Once you're in" — switcher next to the logo; sidebar Dashboard + Firmendaten inside a company; archiving via ⋮ and Deaktiviert on the start page; update the test count. Search the collection for "Firmen verwalten", "Alle Firmen", "company menu", "switcher" and patch stale hits.
- [ ] **Step 5:** Full check; commit `docs: describe the top-bar switcher and archiving on the picker`. Run `php artisan migrate` (expect nothing) and `curl` `/admin/login` → 200.
