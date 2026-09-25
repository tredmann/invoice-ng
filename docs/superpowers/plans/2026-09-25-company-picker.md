# Company Picker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `/admin` renders the full panel layout with one tile per company the user belongs to, never forces registration, and the company dashboard loses its default widgets.

**Architecture:** A full-layout Filament `Page` (`SelectCompany`, not discovered) is served on Filament's own `/admin` route by rebinding `RedirectToTenantController` in the container. The panel gets a no-company state: Filament's company menu is switched off when there is no tenant, a render hook draws a placeholder "Firma wählen" menu in its place, and navigation is built empty. Archiving loses its last-company guard.

**Tech Stack:** Laravel 13, Filament 5 (panels, schemas), Livewire, Pest, PostgreSQL 17, Docker Compose. No Node — Filament's pre-built CSS only.

**Spec:** `docs/superpowers/specs/2026-09-25-company-picker-design.md`

## Global Constraints

- Every command runs in the container: `docker compose run --rm app <command>`. Nothing is installed on the host.
- PostgreSQL only, including tests (`invoice_test`). Never SQLite.
- No Node, no frontend build. A Tailwind class Filament's shipped CSS does not already contain is never compiled — use Filament components and schema components; inline style only where nothing shipped fits.
- No Filament view is published or overridden (spec §3). Configuration, render hooks, subclasses only.
- Identifiers English; user-facing strings German, in `lang/de/company.php`.
- Larastan stays at level 8. Run Rector *before* Pint.
- Before every commit: `docker compose run --rm app sh -c './vendor/bin/rector process && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=512M && ./vendor/bin/pest'` — all green.
- Commits: this machine has no git identity. Commit with `git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit …` and end every message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Branch: `feat/company-picker`.
- Tests never touch the development database `invoice`. Every company a test needs comes from `Company::factory()` inside the test.
- Every test answers "what would make this fail?" — assert something the broken version cannot produce.

## Review Focus

1. **Company names with markup or ampersands** (`Müller & Söhne <b>GmbH</b>`) must print escaped on tiles and in the placeholder menu — pinned in Task 3 and Task 4.
2. **A user whose companies are all archived** must see the empty state at `/admin`, not a tile and not registration — pinned in Task 3.
3. **A logged-out user following a bookmarked company URL** must land on that URL after login, not on the picker — pinned in Task 3.
4. **Typing into the top-bar search at `/admin`** must not error — answered by the Task 1 spike, pinned in Task 3.
5. **Very long company names** must wrap inside a tile rather than overflow it — checked by screenshot in Task 6 (a demo company with a 90-character name).

---

## File map

| File | Responsibility | Task |
|---|---|---|
| `app/Models/Company.php` | `archive()` without the last-company guard; `canBeArchived()` removed | 2 |
| `app/Exceptions/CannotArchiveLastCompany.php` | deleted | 2 |
| `app/Filament/Resources/Companies/Tables/CompaniesTable.php` | archive action visible on any active company | 2 |
| `app/Filament/Pages/Tenancy/SelectCompany.php` | the `/admin` page: tiles, empty state, header action | 3 |
| `resources/views/filament/pages/select-company.blade.php` | deleted (simple-layout view) | 3 |
| `resources/views/filament/pages/company-tile.blade.php` | one tile | 3 |
| `app/Providers/Filament/AdminPanelProvider.php` | no-company state: menu, navigation, render hook, dashboard, widgets | 3, 4, 5 |
| `resources/views/filament/company-picker-menu.blade.php` | the placeholder "Firma wählen" menu | 4 |
| `app/Filament/Pages/Dashboard.php` | dashboard without widgets, with the settings hint | 5 |
| `lang/de/company.php` | new labels | 3, 4, 5 |
| `app/Providers/AppServiceProvider.php`, `app/Http/Responses/LoginResponse.php` | already on the branch, unchanged | — |
| `tests/Feature/CompanyPickerTest.php` | picker, menu, navigation, login | 3, 4 |
| `tests/Feature/CompanyTest.php`, `tests/Feature/CompanyListTest.php` | archiving | 2 |
| `tests/Feature/CompanyTenancyTest.php` | no forced registration | 3 |
| `tests/Feature/PanelTest.php`, `tests/Feature/DashboardTest.php` | dashboard | 5 |
| `README.md`, specs, Outline | docs | 7 |

---

### Task 1: Spike — settle spec §4 (throwaway)

Nothing from this task is committed except the findings written into this plan. It answers three questions by screenshot before any real code depends on them.

**Files (all temporary, deleted at the end of the task):**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `app/Filament/Pages/Tenancy/SelectCompany.php`
- Create: `resources/views/filament/company-picker-menu.blade.php`

**Interfaces:**
- Produces: a "Spike findings" section appended to this plan, fixing (a) the render hook and wrapper markup for the placeholder menu, (b) the navigation mechanism, (c) whether global search stays at `/admin`, and how.

- [ ] **Step 1: Record the starting point**

Run: `git status --short && git stash list`
Expected: clean tree. The spike is undone at Step 8 by restoring exactly these files.

- [ ] **Step 2: Make `SelectCompany` a full-layout page (spike version)**

Replace the body of `app/Filament/Pages/Tenancy/SelectCompany.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;

class SelectCompany extends Page
{
    protected static bool $isDiscovered = false;

    public function getTitle(): string
    {
        return __('company.picker.title');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([Text::make('spike')]);
    }
}
```

- [ ] **Step 3: Add the no-company panel state (spike version)**

In `AdminPanelProvider::panel()`, add after `->tenantMenuItems([...])`:

```php
            ->tenantMenu(fn (): bool => Filament::getTenant() !== null)
            ->navigation(fn (): NavigationBuilder|bool => Filament::getTenant() === null ? new NavigationBuilder : true)
            ->renderHook(
                PanelsRenderHook::SIDEBAR_NAV_START,
                fn (): string => Filament::getTenant() === null
                    ? view('filament.company-picker-menu')->render()
                    : '',
            )
```

with imports `Filament\Facades\Filament`, `Filament\Navigation\NavigationBuilder`, `Filament\View\PanelsRenderHook`.

Create `resources/views/filament/company-picker-menu.blade.php`:

```blade
<div class="fi-sidebar-header-controls" data-company-picker-menu>
    <x-filament::dropdown placement="bottom-start" size class="fi-tenant-menu">
        <x-slot name="trigger">
            <button type="button" class="fi-tenant-menu-trigger">
                <x-filament::icon icon="heroicon-o-building-office-2" class="fi-tenant-avatar" />
                <span class="fi-tenant-menu-trigger-text">
                    <span class="fi-tenant-menu-trigger-tenant-name">{{ __('company.picker.title') }}</span>
                </span>
                <x-filament::icon icon="heroicon-m-chevron-down" />
            </button>
        </x-slot>
        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item tag="a" :href="filament()->getTenantRegistrationUrl()" icon="heroicon-m-plus">
                {{ __('company.actions.create') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
```

- [ ] **Step 4: Create throwaway screenshot users in the development database**

```sh
docker compose run --rm app php artisan tinker --execute='
$two = App\Models\User::query()->create(["name" => "Picker Two", "email" => "picker-two@example.test", "password" => "password"]);
$two->companies()->attach(App\Models\Company::query()->create(["name" => "Demo Alpha GmbH", "legal_form" => "gmbh"]));
$two->companies()->attach(App\Models\Company::query()->create(["name" => "Demo Beta Handel", "legal_form" => "sole_proprietorship"]));
App\Models\User::query()->create(["name" => "Picker None", "email" => "picker-none@example.test", "password" => "password"]);
echo "ok";'
```

Expected: `ok`. These users and their companies are deleted in Task 6.

- [ ] **Step 5: Screenshot `/admin` and `/admin/{company}`**

Script at `$SCRATCH/shot/shot.py` (`$SCRATCH` = the session scratchpad directory):

```python
import sys
from playwright.sync_api import sync_playwright
email, pages, prefix = sys.argv[1], sys.argv[2].split(","), sys.argv[3]
with sync_playwright() as p:
    b = p.chromium.launch()
    for scheme in ["light", "dark"]:
        pg = b.new_page(viewport={"width": 1280, "height": 800}, color_scheme=scheme)
        pg.goto("http://localhost:8080/admin/login")
        pg.fill("input[type=email]", email)
        pg.fill("input[type=password]", "password")
        pg.click("button[type=submit]")
        pg.wait_for_load_state("networkidle")
        for path in pages:
            pg.goto("http://localhost:8080" + path)
            pg.wait_for_load_state("networkidle")
            name = path.strip("/").replace("/", "_") or "root"
            pg.screenshot(path=f"/out/{prefix}-{name}-{scheme}.png")
            if path == "/admin":
                pg.click("[data-company-picker-menu] button")
                pg.wait_for_timeout(400)
                pg.screenshot(path=f"/out/{prefix}-{name}-menu-{scheme}.png")
                pg.keyboard.press("Escape")
                search = pg.locator("input[type=search]").first
                if search.count():
                    search.fill("Demo")
                    pg.wait_for_timeout(1200)
                    pg.screenshot(path=f"/out/{prefix}-{name}-search-{scheme}.png")
        pg.context.close()
    b.close()
```

Run:

```sh
cd $SCRATCH/shot && docker run --rm --network host -v "$PWD":/out mcr.microsoft.com/playwright/python:v1.55.0-noble \
  sh -c "pip install -q playwright==1.55.0 && python /out/shot.py picker-two@example.test /admin,/admin/demo-alpha-gmbh spike"
docker compose logs --since 5m app | grep -iE "error|exception" | head
```

Expected: PNGs for each page; read every one with the Read tool.

- [ ] **Step 6: Answer the three questions**

1. **Placement (§4.1):** compare `spike-admin-light.png` against `spike-admin_demo-alpha-gmbh-light.png`. The placeholder must sit where Filament's company menu sits, same width and padding. If it does not, retry with `PanelsRenderHook::SIDEBAR_LOGO_AFTER` and/or without the `fi-sidebar-header-controls` wrapper; keep the variant that matches.
2. **Navigation (§4.2):** `/admin` sidebar shows no links, and the sidebar frame is present; `/admin/demo-alpha-gmbh` still shows "Dashboard". If `navigation(Closure)` breaks the company pages, fall back to overriding `shouldRegisterNavigation()` — record which.
3. **Search (§4.3):** does `spike-admin-search-*.png` show results or an error, and did `docker compose logs` show an exception? Outcomes:
   - works → search stays; Task 3 carries a test that it keeps working.
   - errors because a result URL needs a tenant → record the exception text; Task 3 gives `CompanyResource` a `getGlobalSearchResultUrl()` that passes the record itself as the tenant (see Task 3 Step 7, alternative B).
   - errors for another reason → stop and ask the user (spec §4).

- [ ] **Step 7: Write the findings into this plan**

Append a `## Spike findings` section at the end of this file: the chosen hook and wrapper (paste the final menu Blade), the navigation mechanism, the search outcome with any exception text, and the screenshot file names. Where a finding changes code in Tasks 3–4, edit those steps now so they match.

- [ ] **Step 8: Throw the spike away and commit only the plan**

```sh
git checkout -- app/Providers/Filament/AdminPanelProvider.php app/Filament/Pages/Tenancy/SelectCompany.php
rm resources/views/filament/company-picker-menu.blade.php
git status --short   # expected: only the plan file modified
git add docs/superpowers/plans/2026-09-25-company-picker.md
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "docs: record the company picker spike findings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Allow archiving the last company

**Files:**
- Modify: `app/Models/Company.php` (docblock + `canBeArchived()` + `archive()` + `activeCompanyCountFor()`, around lines 105–173)
- Delete: `app/Exceptions/CannotArchiveLastCompany.php`
- Modify: `app/Filament/Resources/Companies/Tables/CompaniesTable.php` (archive action, `actingUser()`)
- Test: `tests/Feature/CompanyTest.php`, `tests/Feature/CompanyListTest.php`

**Interfaces:**
- Produces: `Company::archive(): void` (no parameter; a no-op on an already-archived company). `Company::canBeArchived()` no longer exists — callers use `! $company->isArchived()`.

- [ ] **Step 1: Rewrite the archiving tests**

In `tests/Feature/CompanyTest.php`, replace the tests `archives a company while another active one remains`, `refuses to archive the last active company`, `does not count already-archived companies as the ones keeping the lights on` and `reports an already-archived company as not archivable again` with:

```php
it('archives a company while another active one remains', function (): void {
    $keep = Company::factory()->create();
    $company = Company::factory()->create();

    $company->archive();

    expect($company->fresh()?->isArchived())->toBeTrue()
        ->and($keep->fresh()?->isArchived())->toBeFalse();
});

it('archives the last active company', function (): void {
    // This used to be refused: with no active company left, Filament forced
    // every request into registration and nothing outside a company existed to
    // recover from. /admin now renders without a company and never forces
    // registration, so the dead end the guard prevented is gone.
    $only = Company::factory()->create();

    $user = User::factory()->create();
    $user->companies()->attach($only);

    $only->archive();

    expect($only->fresh()?->isArchived())->toBeTrue();
});

it('leaves the archive date of an already archived company alone', function (): void {
    // Archiving twice must not move the date: it records when the company went
    // out of use, and a second click is not that moment.
    $company = Company::factory()->create(['archived_at' => now()->subYear()]);
    $original = $company->archived_at?->toIso8601String();

    $company->archive();

    expect($company->fresh()?->archived_at?->toIso8601String())->toBe($original);
});
```

Remove the `use App\Exceptions\CannotArchiveLastCompany;` import.

In `tests/Feature/CompanyListTest.php`, replace `hides the archive action on the last active company` with:

```php
it('archives the last active company from the list, and /admin still renders', function (): void {
    /** @var TestCase $this */
    // End to end, the reason the guard could go: after archiving the only
    // company, /admin is a page, not a redirect into registration.
    $only = Company::factory()->create(['name' => 'Only GmbH']);

    $user = User::factory()->create();
    $user->companies()->attach($only);

    Livewire::actingAs($user);

    Filament::setTenant($only);

    Livewire::test(ListCompanies::class)
        ->assertTableActionVisible('archive', $only)
        ->callTableAction('archive', $only);

    expect($only->fresh()?->isArchived())->toBeTrue();
});

it('hides the archive action on an archived company', function (): void {
    $active = Company::factory()->create();
    $archived = Company::factory()->archived()->create();

    $user = User::factory()->create();
    $user->companies()->attach([$active->getKey(), $archived->getKey()]);

    Livewire::actingAs($user);

    Filament::setTenant($active);

    Livewire::test(ListCompanies::class)
        ->assertTableActionHidden('archive', $archived);
});
```

(The `/admin` half of the first test's name is asserted in Task 3, whose page makes it true; this test pins the list half.)

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTest.php tests/Feature/CompanyListTest.php`
Expected: FAIL — `archive()` requires a `User` argument (`ArgumentCountError`), and the list hides the archive action on the only company.

- [ ] **Step 3: Remove the guard from the model**

In `app/Models/Company.php`, delete the `canBeArchived()` method with its docblock, delete `activeCompanyCountFor()` with its docblock, remove the `CannotArchiveLastCompany` import and the `DB` import if nothing else uses it, and replace `archive()` with:

```php
    /**
     * Archives the company. An already archived company keeps its original
     * archive date.
     *
     * There is no guard against archiving the user's last active company. One
     * existed while that was a dead end — Filament forced a user with no
     * companies into registration — and went when /admin became a page that
     * renders without a company (company-picker spec §2.5).
     */
    public function archive(): void
    {
        if ($this->isArchived()) {
            return;
        }

        // forceFill because archived_at is deliberately not fillable: it is
        // state, changed through these two methods and nowhere else.
        $this->forceFill(['archived_at' => now()])->save();
    }
```

Delete `app/Exceptions/CannotArchiveLastCompany.php`.

- [ ] **Step 4: Update the table action**

In `CompaniesTable.php`, replace the archive action's `visible`/`action` and its comment:

```php
                    Action::make('archive')
                        ->label(__('company.actions.archive'))
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->requiresConfirmation()
                        ->visible(fn (Company $record): bool => ! $record->isArchived())
                        ->action(fn (Company $record) => $record->archive()),
```

Delete `actingUser()` and its docblock if nothing else in the file calls it, and the now-unused `User`/`Auth` imports.

- [ ] **Step 5: Run the full check**

Run: `docker compose run --rm app sh -c './vendor/bin/rector process && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=512M && ./vendor/bin/pest'`
Expected: all green. `grep -rn "CannotArchiveLastCompany\|canBeArchived\|activeCompanyCountFor" app tests` prints nothing.

- [ ] **Step 6: Commit**

```sh
git add -A app tests
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "feat: allow archiving the last active company

The guard existed because a user with no active company was forced into
registration with no screen outside a company to recover from. /admin now
renders without one, so the dead end is gone and the guard with it.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `/admin` in the full layout — tiles, empty state, no forced registration

**Files:**
- Modify (rewrite): `app/Filament/Pages/Tenancy/SelectCompany.php`
- Delete: `resources/views/filament/pages/select-company.blade.php`
- Create: `resources/views/filament/pages/company-tile.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `lang/de/company.php`
- Test (rewrite): `tests/Feature/CompanyPickerTest.php`; modify `tests/Feature/CompanyTenancyTest.php`, `tests/Feature/CompanyListTest.php`

**Interfaces:**
- Consumes: `User::getTenants(Panel): Collection<int, Company>`; `Company::$legal_form` (`LegalForm`, `getLabel()`); the existing container binding `RedirectToTenantController → SelectCompany` and `LoginResponse`.
- Produces: `SelectCompany::getCompanies(): Collection<int, Company>` (used by Task 4's render hook); lang keys `company.picker.title`, `company.picker.empty`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/CompanyPickerTest.php` with:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;
use Tests\TestCase;

it('renders /admin in the full panel layout', function (): void {
    /** @var TestCase $this */
    // The first attempt used the simple layout — the centred card login uses —
    // which has neither of these. The spec's success criterion is that /admin
    // looks like every other panel screen.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create());

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee('fi-sidebar', escape: false)
        ->assertSee('fi-topbar', escape: false);
});

it('shows the companies of the user as tiles, in name order, with the legal form', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->soleProprietorship()->create(['name' => 'Zeta Handel']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Alpha GmbH']));

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSeeInOrder(['Alpha GmbH', 'Zeta Handel'])
        ->assertSee('Einzelunternehmen')
        ->assertDontSee('sole_proprietorship');
});

it('links each tile to its company by slug', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSeeHtml('href="'.url('/admin/acme-gmbh').'"');
});

it('leaves archived companies and other users companies off the picker', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Mine GmbH']));
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Archived GmbH']));
    Company::factory()->create(['name' => 'Theirs GmbH']);

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Mine GmbH')
        ->assertDontSee('Archived GmbH')
        ->assertDontSee('Theirs GmbH');
});

it('escapes company names on tiles', function (): void {
    /** @var TestCase $this */
    // Review focus 1: a name is user input and prints into HTML.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Müller & Söhne <b>GmbH</b>']));

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Müller &amp; Söhne &lt;b&gt;GmbH&lt;/b&gt;', escape: false)
        ->assertDontSee('<b>GmbH</b>', escape: false);
});

it('offers a new company from the page header', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create());

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSeeHtml('href="'.url('/admin/new').'"');
});

it('shows an empty state, not registration, to a user with no company', function (): void {
    /** @var TestCase $this */
    // Filament's own /admin redirected here to /admin/new. The user now
    // chooses to create a company; nothing forces it.
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk()
        ->assertSee('Noch keine Firma angelegt.')
        ->assertSeeHtml('href="'.url('/admin/new').'"');
});

it('shows the empty state to a user whose companies are all archived', function (): void {
    /** @var TestCase $this */
    // Review focus 2: archived companies leave getTenants(), so this user has
    // nothing to pick — and must not get a tile for the archived one.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Noch keine Firma angelegt.')
        ->assertDontSee('Gone GmbH');
});

it('lands on the picker after login, not inside a company', function (): void {
    $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'secret-password']);
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    Livewire::test(Login::class)
        ->fillForm(['email' => 'user@example.com', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect(url('/admin'));
});

it('still sends a login to the page the user was on the way to', function (): void {
    /** @var TestCase $this */
    // Review focus 3: a bookmarked company link must survive the login detour.
    $user = User::factory()->create(['email' => 'user@example.com', 'password' => 'secret-password']);
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->get('/admin/acme-gmbh/settings')->assertRedirect('/admin/login');

    Livewire::test(Login::class)
        ->fillForm(['email' => 'user@example.com', 'password' => 'secret-password'])
        ->call('authenticate')
        ->assertRedirect(url('/admin/acme-gmbh/settings'));
});

it('offers the way back to the picker from inside a company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Alle Firmen');
});
```

In `tests/Feature/CompanyTenancyTest.php`, replace `sends a user with no company to company registration` with:

```php
it('does not force a user with no company into registration', function (): void {
    /** @var TestCase $this */
    // The first company used to come into being by Filament redirecting /admin
    // to registration. The user now creates it by choice, from /admin.
    $this->actingAs(User::factory()->create())
        ->get('/admin')
        ->assertOk();
});
```

In `tests/Feature/CompanyListTest.php`, extend `archives the last active company from the list, and /admin still renders` (Task 2) by appending before its closing `});`:

```php
    $this->actingAs($user)
        ->get('/admin')
        ->assertOk()
        ->assertSee('Noch keine Firma angelegt.');
```

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyPickerTest.php tests/Feature/CompanyTenancyTest.php tests/Feature/CompanyListTest.php`
Expected: FAIL — `fi-sidebar` not found (simple layout), and the no-company tests get a 302 to `/admin/new`.

- [ ] **Step 3: Add the labels**

In `lang/de/company.php`, extend the `picker` block:

```php
    'picker' => [
        'title' => 'Firma wählen',
        'empty' => 'Noch keine Firma angelegt.',
    ],
```

- [ ] **Step 4: Rewrite `SelectCompany` as a full-layout page**

`app/Filament/Pages/Tenancy/SelectCompany.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;

/**
 * The page at /admin: one tile per company the user can switch to.
 *
 * It sits outside any company but in the full panel layout, so it looks like
 * every other screen (company-picker spec §1). Filament would otherwise
 * redirect /admin into the default company, or into registration when there is
 * none; AppServiceProvider binds Filament's RedirectToTenantController to this
 * page instead, so it is served on Filament's own route. It never redirects.
 *
 * Not discovered: discovery would also register it under every company.
 */
class SelectCompany extends Page
{
    protected static bool $isDiscovered = false;

    public function getTitle(): string
    {
        return __('company.picker.title');
    }

    /**
     * The same list as the company menu, so the two cannot disagree — archived
     * companies stay out of both.
     *
     * @return Collection<int, Company>
     */
    public static function getCompanies(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->getTenants(Filament::getDefaultPanel());
    }

    public function content(Schema $schema): Schema
    {
        $companies = static::getCompanies();

        if ($companies->isEmpty()) {
            return $schema->components([
                EmptyState::make(__('company.picker.empty'))
                    ->icon(Heroicon::OutlinedBuildingOffice2)
                    ->footer([$this->createCompanyAction()]),
            ]);
        }

        return $schema->components([
            Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema($companies
                    ->map(fn (Company $company): View => View::make('filament.pages.company-tile')
                        ->key("company-{$company->getKey()}")
                        ->viewData([
                            'name' => $company->name,
                            'legalForm' => $company->legal_form->getLabel(),
                            'url' => Filament::getDefaultPanel()->getUrl($company),
                        ]))
                    ->all()),
        ]);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // With no company the empty state carries this action; showing it in
        // the header as well would put the same button on the page twice.
        return static::getCompanies()->isEmpty() ? [] : [$this->createCompanyAction()];
    }

    private function createCompanyAction(): Action
    {
        return Action::make('createCompany')
            ->label(__('company.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->url(Filament::getTenantRegistrationUrl());
    }
}
```

Delete `resources/views/filament/pages/select-company.blade.php`.

Create `resources/views/filament/pages/company-tile.blade.php`:

```blade
{{-- One company on the picker. The whole card is the link. --}}
<a href="{{ $url }}" style="display: block">
    <x-filament::section :heading="$name" :description="$legalForm" />
</a>
```

- [ ] **Step 5: Add the no-company panel state**

In `AdminPanelProvider::panel()`, after the `->tenantMenuItems([...])` call, add (using the variant Task 1 recorded):

```php
            // With no company in the URL — only on /admin — Filament's company
            // menu cannot render: it passes the null tenant to getTenantName().
            // Task 4's placeholder menu takes its place.
            ->tenantMenu(fn (): bool => Filament::getTenant() !== null)
            // Every navigation URL needs a company. An empty builder keeps the
            // sidebar and drops its links; navigation(false) would drop the
            // sidebar itself.
            ->navigation(fn (): NavigationBuilder|bool => Filament::getTenant() === null
                ? new NavigationBuilder
                : true)
```

Imports: `Filament\Facades\Filament`, `Filament\Navigation\NavigationBuilder`.

- [ ] **Step 6: Run the tests**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyPickerTest.php tests/Feature/CompanyTenancyTest.php tests/Feature/CompanyListTest.php`
Expected: PASS.

- [ ] **Step 7: Pin global search at `/admin` (review focus 4)**

Add to `tests/Feature/CompanyPickerTest.php`:

```php
it('answers a global search on /admin without a company', function (): void {
    // Review focus 4: the companies resource is globally searchable, and every
    // result needs a URL — which, inside a company, is built from that company.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    Livewire::actingAs($user)
        ->test(\Filament\Livewire\GlobalSearch::class)
        ->set('search', 'Acme')
        ->assertOk()
        ->assertSee('Acme GmbH');
});
```

Run it — expected FAIL with `UrlGenerationException: Missing required parameter for [Route: filament.admin.resources.companies.index]` (the spike found exactly this). Then apply **B**:
- **A — search worked in the spike:** the test passes; nothing else.
- **B — a result URL needed a tenant:** add to `CompanyResource`:

```php
    /**
     * A company's search result opens that company's settings, with the
     * company itself as the tenant — so the URL builds on /admin too, where no
     * company is current yet.
     */
    public static function getGlobalSearchResultUrl(Model $record): string
    {
        return route(CompanySettings::getRouteName(), ['tenant' => $record]);
    }
```

  (imports `Illuminate\Database\Eloquent\Model`, `App\Filament\Pages\Tenancy\CompanySettings`), re-run, expect PASS.

- [ ] **Step 8: Full check and commit**

Run the Global Constraints check. Expected: all green.

```sh
git add -A app lang resources tests
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "feat: render the company picker in the full panel layout

/admin now uses the same layout as every company screen: tiles in a grid,
an empty state when the user has no active company, and no redirect into
registration. Without a company in the URL the panel hides Filament's
company menu and builds an empty navigation, since both need a tenant.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: The placeholder "Firma wählen" menu

**Files:**
- Create: `resources/views/filament/company-picker-menu.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/CompanyPickerTest.php`

**Interfaces:**
- Consumes: `SelectCompany::getCompanies(): Collection<int, Company>` (Task 3); lang keys `company.picker.title`, `company.actions.create`.
- Produces: markup with the attribute `data-company-picker-menu`, rendered only when `Filament::getTenant() === null`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/CompanyPickerTest.php`:

```php
it('shows the placeholder company menu on /admin, listing the companies', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $response = $this->actingAs($user)->get('/admin')->assertOk();

    // Scoped to the menu's own markup, so the tile's copy of the name and link
    // cannot satisfy these on its own.
    $menu = str($response->getContent())->after('data-company-picker-menu')->before('</nav>');

    expect((string) $menu)
        ->toContain('Firma wählen')
        ->toContain('Acme GmbH')
        ->toContain('href="'.url('/admin/acme-gmbh').'"')
        ->toContain('href="'.url('/admin/new').'"');
});

it('shows Filament company menu, not the placeholder, inside a company', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertDontSee('data-company-picker-menu', escape: false)
        ->assertSee('fi-tenant-menu', escape: false);
});

it('escapes company names in the placeholder menu', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Müller & Söhne <b>GmbH</b>']));

    $content = $this->actingAs($user)->get('/admin')->assertOk()->getContent();
    $menu = (string) str($content)->after('data-company-picker-menu')->before('</nav>');

    expect($menu)
        ->toContain('Müller &amp; Söhne &lt;b&gt;GmbH&lt;/b&gt;')
        ->not->toContain('<b>GmbH</b>');
});

it('builds no navigation links on /admin, but does inside a company', function (): void {
    /** @var TestCase $this */
    // Both halves: without the second, an empty sidebar everywhere would pass.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)->get('/admin')
        ->assertOk()
        ->assertDontSee('fi-sidebar-item', escape: false);

    $this->actingAs($user)->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('fi-sidebar-item', escape: false);
});
```

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyPickerTest.php`
Expected: the two placeholder-menu tests FAIL (no `data-company-picker-menu`); the navigation test already PASSES (Task 3) — it stays as the regression guard.

- [ ] **Step 3: Create the menu view**

`resources/views/filament/company-picker-menu.blade.php` (the spike's version, extended with the company list):

```blade
{{--
    Stands in for Filament's company menu on /admin, where no company is
    current and Filament's own menu cannot render. Built from Filament's
    dropdown components and its menu classes so it looks the same.
--}}
<div class="fi-sidebar-header-controls" data-company-picker-menu>
    <x-filament::dropdown placement="bottom-start" size class="fi-tenant-menu">
        <x-slot name="trigger">
            <button type="button" class="fi-tenant-menu-trigger">
                {{-- An avatar-sized box, like Filament's tenant avatar. Inline
                     style because the trigger's .fi-icon rule pushes a bare
                     icon to the end with margin-inline-start: auto. --}}
                <span class="fi-avatar fi-tenant-avatar" style="display: flex; align-items: center; justify-content: center; background: var(--gray-100)">
                    <x-filament::icon icon="heroicon-o-building-office-2" style="margin: 0" />
                </span>
                <span class="fi-tenant-menu-trigger-text">
                    <span class="fi-tenant-menu-trigger-tenant-name">{{ __('company.picker.title') }}</span>
                </span>
                <x-filament::icon icon="heroicon-m-chevron-down" />
            </button>
        </x-slot>

        @if ($companies->isNotEmpty())
            <x-filament::dropdown.list>
                @foreach ($companies as $company)
                    <x-filament::dropdown.list.item tag="a" :href="filament()->getUrl($company)">
                        {{ $company->name }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        @endif

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item tag="a" :href="filament()->getTenantRegistrationUrl()" icon="heroicon-m-plus">
                {{ __('company.actions.create') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
```

- [ ] **Step 4: Register the render hook**

In `AdminPanelProvider::panel()`, after the `->navigation(...)` call from Task 3 (`SIDEBAR_START`, per the spike findings):

```php
            // SIDEBAR_START, not SIDEBAR_NAV_START: inside the nav the menu
            // inherits the nav's padding and scrollbar gutter. Here it lands in
            // the exact box Filament's own company menu occupies on desktop.
            ->renderHook(
                PanelsRenderHook::SIDEBAR_START,
                fn (): string => Filament::getTenant() === null
                    ? view('filament.company-picker-menu', ['companies' => SelectCompany::getCompanies()])->render()
                    : '',
            )
```

Imports: `Filament\View\PanelsRenderHook`, `App\Filament\Pages\Tenancy\SelectCompany`.

- [ ] **Step 5: Run the tests, full check, commit**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyPickerTest.php` → PASS. Then the Global Constraints check → all green.

```sh
git add -A app resources tests
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "feat: show a Firma wählen menu where the company menu sits on /admin

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Dashboard without default widgets, with the settings hint

**Files:**
- Create: `app/Filament/Pages/Dashboard.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (pages, widgets)
- Modify: `lang/de/company.php`
- Test: create `tests/Feature/DashboardTest.php`; modify `tests/Feature/PanelTest.php`

**Interfaces:**
- Consumes: `CompanySettings::getRouteName(): string`.
- Produces: `App\Filament\Pages\Dashboard`; lang keys `company.dashboard.empty`, `company.dashboard.complete_settings`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/DashboardTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Tests\TestCase;

it('shows neither default widget on the company dashboard', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertDontSee('fi-account-widget', escape: false)
        ->assertDontSee('fi-filament-info-widget', escape: false);
});

it('points an empty dashboard at the company settings', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Acme GmbH']));

    $this->actingAs($user)
        ->get('/admin/acme-gmbh')
        ->assertOk()
        ->assertSee('Noch keine Inhalte.')
        ->assertSeeHtml('href="'.url('/admin/acme-gmbh/settings').'"');
});
```

In `tests/Feature/PanelTest.php`, in `shows the dashboard of the users company`, replace `->assertSee($user->name);` with `->assertSee('Noch keine Inhalte.');` and replace the comment's second paragraph with: "Asserting on the dashboard's own hint: the account widget that used to print the user's name is gone."

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/DashboardTest.php tests/Feature/PanelTest.php`
Expected: FAIL — `fi-account-widget` present, hint absent.

- [ ] **Step 3: Add the labels**

In `lang/de/company.php`, add:

```php
    'dashboard' => [
        'empty' => 'Noch keine Inhalte.',
        'complete_settings' => 'Firmendaten vervollständigen',
    ],
```

- [ ] **Step 4: Create the dashboard**

`app/Filament/Pages/Dashboard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Tenancy\CompanySettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The company dashboard, until invoicing gives it content.
 *
 * Filament's two default widgets are gone. An empty screen says what to do
 * next (.ai/guidelines/ui/core.blade.php), and today the only useful next step
 * is completing the company data.
 */
class Dashboard extends BaseDashboard
{
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make(__('company.dashboard.empty'))
                ->icon(Heroicon::OutlinedInbox)
                ->footer([
                    Action::make('completeSettings')
                        ->label(__('company.dashboard.complete_settings'))
                        ->icon(Heroicon::OutlinedArrowRight)
                        ->iconPosition('after')
                        ->link()
                        ->url(route(CompanySettings::getRouteName(), ['tenant' => Filament::getTenant()])),
                ]),
        ]);
    }
}
```

- [ ] **Step 5: Wire it into the panel**

In `AdminPanelProvider`: replace `->pages([Dashboard::class])` so it references `App\Filament\Pages\Dashboard` (change the import from `Filament\Pages\Dashboard`), and delete the `->widgets([AccountWidget::class, FilamentInfoWidget::class])` call with both imports.

- [ ] **Step 6: Run, full check, commit**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/DashboardTest.php tests/Feature/PanelTest.php` → PASS. Then the Global Constraints check.

```sh
git add -A app lang tests
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "feat: replace the default dashboard widgets with a settings hint

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Look at it

Rendered pages need eyes (`CLAUDE.md`). This task changes no code unless it finds a defect; a defect goes back to the task that owns it, with a test.

**Files:** none in the repository. Scratch: `$SCRATCH/shot/`.

- [ ] **Step 1: Add the long-name demo company (review focus 5)**

```sh
docker compose run --rm app php artisan tinker --execute='
$u = App\Models\User::query()->where("email", "picker-two@example.test")->sole();
$u->companies()->attach(App\Models\Company::query()->create(["name" => "Demo Gamma Internationale Handels- und Beteiligungsgesellschaft für Softwareentwicklung mbH", "legal_form" => "gmbh"]));
echo "ok";'
```

(If Task 1's users were already removed, re-run Task 1 Step 4 first.)

- [ ] **Step 2: Screenshot all views**

```sh
cd $SCRATCH/shot
docker run --rm --network host -v "$PWD":/out mcr.microsoft.com/playwright/python:v1.55.0-noble sh -c "pip install -q playwright==1.55.0 && \
  python /out/shot.py picker-two@example.test /admin,/admin/demo-alpha-gmbh final-two && \
  python /out/shot.py picker-none@example.test /admin final-none && \
  python /out/shot.py tobias.redmann@gmail.com /admin,/admin/balt final-owner"
```

The owner's account is only viewed. Read every PNG.

- [ ] **Step 3: Check against the spec**

For each, light and dark: top bar and sidebar match `/admin/{company}` (spec §1); the placeholder menu sits where the company menu does (§2.1); tiles show name and legal form, and the long name wraps inside its tile; the empty state shows "Noch keine Firma angelegt." and "Neue Firma" (§2.2); the company dashboard shows the hint and no widgets (§2.4); search at `/admin` shows results, not an error.

- [ ] **Step 4: Remove the throwaway users and their companies**

```sh
docker compose run --rm app php artisan tinker --execute='
foreach (App\Models\User::query()->whereIn("email", ["picker-two@example.test", "picker-none@example.test"])->get() as $u) {
    $ids = $u->companies()->pluck("companies.id");
    $u->companies()->detach();
    App\Models\Company::query()->whereKey($ids)->delete();
    $u->delete();
}
echo App\Models\User::query()->count(), " users left";'
docker compose exec -T db psql -U invoice -d invoice -c "select name from companies;"
```

Expected: only the owner's user and companies remain (today: `BALT`).

---

### Task 7: Correct what this falsified

**Files:**
- Modify: `README.md`
- Modify: `docs/superpowers/specs/2026-09-23-invoice-system-design.md` (§3.2 note)
- Modify: `docs/superpowers/specs/2026-09-24-companies-and-tenancy-design.md` (remove the note the first attempt added)
- Modify: `docs/superpowers/specs/2026-09-25-company-picker-design.md` (two clarifications)
- Check: `CLAUDE.md`
- Outline: "Domain Model", "Running It Locally", plus search results

- [ ] **Step 1: README**

Replace the "A start page with all your companies" bullet with:

```markdown
- **A start page with all your companies.** After logging in you land on
  `/admin`, laid out like every other screen, with one tile per active company
  — its name and legal form — and a button to set up a new one. Click a tile to
  work in that company; from inside a company, "Alle Firmen" in the company menu
  brings you back. With no company yet, the page says so and offers to create
  one — nothing makes you.
```

In the "Companies are deactivated, never deleted" bullet, replace "Archiving the last company you still have active is refused — it would leave you with nowhere to go." with "Archiving your last active company is allowed; you land back on the start page."

In the first-run step 7, replace "so the first login lands on company registration rather than the company picker — that is expected, not a broken login." with "so the first login shows an empty start page with a button to create the first company."

- [ ] **Step 2: System design §3.2**

Replace the `> **Corrected 2026-09-25.**` note under "A current company is selected on login" with:

```markdown
> **Corrected 2026-09-25.** "Selected on login" is now literal: login lands on
> a company picker at `/admin`, in the full panel layout, with one tile per
> non-archived company the user belongs to, instead of redirecting straight into
> a default company. Nothing forces a user into company registration; with no
> company the picker says so and offers it. The picker shows which companies
> exist, not any of their data, so it is not a cross-company view. See
> `docs/superpowers/specs/2026-09-25-company-picker-design.md`.
```

- [ ] **Step 3: Take back the note in the dated companies spec**

In `2026-09-24-companies-and-tenancy-design.md`, delete the whole `> **Corrected 2026-09-25 — a fifth surface, the company picker.**` block under "**The switcher**" (its §10 says the file is not updated when the stack moves). Verify: `git diff main -- docs/superpowers/specs/2026-09-24-companies-and-tenancy-design.md` prints nothing.

- [ ] **Step 4: Record two clarifications in the new spec**

In `2026-09-25-company-picker-design.md`:
- §2.1, after the "Content" bullet, add: "With no company, the header action is not shown — the empty state carries the same action, and the page would otherwise show it twice."
- §2.5, replace "`canBeArchived()` still refuses a company that is already archived. Only the last-company condition, its row lock, and `CannotArchiveLastCompany` go." with "An already archived company offers no archive action, and archiving it again leaves its archive date alone. `canBeArchived()`, which existed only to carry the last-company condition, goes with it, as do its row lock and `CannotArchiveLastCompany`."
- §4, append the spike outcome in one line per question (from "Spike findings").

- [ ] **Step 5: CLAUDE.md**

Run: `grep -n -i "registration\|last company\|last active" CLAUDE.md`. For each hit that describes forced registration or the last-company guard as current, correct it. If none, change nothing.

- [ ] **Step 6: Outline**

Load `mcp__outline__fetch`, `mcp__outline__update_document`, `mcp__outline__list_documents` via ToolSearch. Then:
- "Running It Locally" (`f5e5c024-bd44-4e73-b627-ccee8ac78d01`): patch the "Once you're in" paragraph to: no-company account → empty start page at `/admin` with "Neue Firma"; account with companies → picker in the full layout; "Alle Firmen" leads back. Update the test count in "What works today" to the final `pest` count.
- "Domain Model" (`43d5e40d-dc2f-4181-ab0c-7bd5883d7cc0`): patch its 2026-09-25 note to the same text as Step 2 (without the repository path).
- Search `list_documents` for "registration", "last active company", "dead end", "Neue Firma" in collection `5a1d9cca-63d4-4d89-b70a-18522ec13cf6`; patch each page that states forced registration or the archive guard as current, with `editMode: "patch"`.

- [ ] **Step 7: Full check and commit**

Run the Global Constraints check → all green.

```sh
git add -A README.md CLAUDE.md docs
git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit -m "docs: correct what the full-layout company picker falsified

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 8: Development database**

Run: `docker compose run --rm app php artisan migrate` — expected "Nothing to migrate" (this change adds none; run anyway, per `CLAUDE.md`). Then `curl -s -o /dev/null -w "%{http_code}\n" http://localhost:8080/admin/login` → `200`.

---

## Spike findings (Task 1, 2026-09-25)

Screenshots in the session scratchpad: `spike-*.png`, `spike2-*.png`, `mobile-*.png`.

1. **Placement (§4.1).** `SIDEBAR_NAV_START` put the menu inside the nav: x 24 / y 96 / width 257 against Filament's x 0 / y 64 / width 320 — the nav's 24px padding and 15px scrollbar gutter. `SIDEBAR_START` with the `fi-sidebar-header-controls` wrapper measures **identical** to Filament's menu on desktop (controls 0/64/320×73, trigger 16/76/288×48, avatar 24/84/32×32). A bare heroicon as the avatar was pushed right by `.fi-tenant-menu-trigger .fi-icon { margin-inline-start: auto }`; an avatar-sized `fi-avatar` span holding the icon fixes it. Deviation: on a phone-width drawer, `SIDEBAR_START` renders the menu *above* the sidebar header (logo), where Filament's menu sits below it. The sidebar header is `display: none` on desktop with a top bar, so desktop is unaffected. `Ruling` in the ledger.
2. **Navigation (§4.2).** `->navigation(fn () => Filament::getTenant() === null ? new NavigationBuilder : true)` works: at `/admin` the sidebar frame stays and has no items; inside a company "Dashboard" is still there. The top bar's open-sidebar button stays (it keys off `hasNavigation()`, which a builder satisfies).
3. **Search (§4.3).** Typing "Demo" at `/admin` → 500, `Illuminate\Routing\Exceptions\UrlGenerationException: Missing required parameter for [Route: filament.admin.resources.companies.index] [URI: admin/{tenant}/companies] [Missing parameter: tenant]`. Outcome **B**: `CompanyResource::getGlobalSearchResultUrl()` passes the record as the tenant (Task 3 Step 7).
