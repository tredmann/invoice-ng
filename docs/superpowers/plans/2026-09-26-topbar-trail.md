# Top-Bar Trail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the company switcher the first crumb of a breadcrumb trail in the top bar, aligned with the page content, drop Filament's breadcrumbs above the heading, and give the switcher's dropdown the concept's look and entries.

**Architecture:** A subclass of Filament's `Topbar` Livewire component holds the trail in a locked public property, taken at `mount()` from the page that is rendering the layout (`Livewire::current()`). A small builder, `App\Filament\Breadcrumbs`, turns a page's Filament breadcrumbs into the agreed trail. The existing `TOPBAR_LOGO_AFTER` render hook draws switcher and trail from one Blade view; one injected stylesheet (a Blade view at `STYLES_AFTER`) positions the trail over the content column and styles avatars and crumbs. No Filament view is published or overridden.

**Tech Stack:** Laravel 13, Filament 5.8.4, Livewire 4.4.6, Pest, PostgreSQL 17, PHP 8.5 (`Dom\HTMLDocument` for parsing test HTML), Docker Compose. No Node — Filament's pre-built CSS plus our injected `<style>`.

**Spec:** `docs/superpowers/specs/2026-09-26-topbar-trail-design.md` (§2–§5 are what this plan implements; §6 lists the documents it corrects).

## Global Constraints

- Every command runs in the container: `docker compose run --rm app <command>`.
- PostgreSQL only, including tests (`invoice_test`). Tests never touch the development database `invoice`; companies and customers come from factories.
- No Node, no frontend build. Classes we style ourselves are prefixed `app-` and defined in `resources/views/filament/topbar-styles.blade.php`; Filament's shipped classes (`fi-tenant-menu`, `fi-tenant-menu-trigger`, …) are reused as they are.
- No Filament view published or overridden.
- Identifiers English; user-facing strings German in `lang/de/`.
- Trail rule (spec §2.2): switcher › section › record › page; no "Übersicht" (Filament's German list crumb) and no "Ansehen" (view crumb); "Erstellen" replaced by the page title; the last crumb is not a link.
- Dropdown (spec §2.5): label "Firma wechseln"; active companies with initials avatar, current one marked with ✓; divider; "Firmen verwalten" → `/admin` (left out on `/admin`); "Neue Firma" → `/admin/new`.
- Alignment only from 64rem and only with a sidebar (`.fi-body-has-navigation`); `/admin` keeps the switcher directly after the logo; phones unchanged, no trail.
- Larastan level 8. Rector before Pint.
- Before every commit: `docker compose run --rm app sh -c './vendor/bin/rector process && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress && ./vendor/bin/pest'` — all green.
- Commit with `git -c user.name="Tobias Redmann" -c user.email="tobias.redmann@gmail.com" commit …`, message ending `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Branch `feat/topbar-trail`, created from `main` before Task 1.
- Every test answers "what would make this fail?"

## Review Focus

1. **A deactivated customer's page**: the heading carries a "Deaktiviert" badge (an `HtmlString`), but the trail must show the plain name — no badge markup, no escaped `<span`. Pinned in Task 2.
2. **Names with markup or ampersands** (`Bauer & <Söhne> GmbH`) print escaped exactly once in the trail and in the dropdown — not as markup, not as `&amp;amp;`. Pinned in Tasks 2 and 3.
3. **A company name with no leading letter** (`(Neu) Handel`, `!!!`) still gets an avatar: "NH", and a single character for a name without letters or digits — never an empty square or an exception. Pinned in Task 3.
4. **A user who belongs only to an archived company, opened by URL**: the switcher is shown, names the archived company, lists no company, and still offers "Firmen verwalten" and "Neue Firma" — the dropdown is never empty. Pinned in Task 3.
5. **The user menu stays clickable** with the trail column positioned over the top bar at 1920 px. Checked by screenshot and a click in Task 4 — CSS stacking cannot be asserted in Pest.

---

## File map

| File | Responsibility | Task |
|---|---|---|
| `app/Filament/Breadcrumbs.php` | page → trail (create) | 2 |
| `app/Livewire/Topbar.php` | holds the trail across re-renders (create) | 2 |
| `resources/views/filament/topbar-trail.blade.php` | switcher + trail (renamed from `company-switcher.blade.php`) | 2, 3 |
| `resources/views/filament/topbar-styles.blade.php` | injected CSS: phone rule, trail, avatars, alignment (create) | 2, 3, 4 |
| `app/Providers/Filament/AdminPanelProvider.php` | `breadcrumbs(false)`, `topbarLivewireComponent()`, hooks | 2 |
| `app/Filament/Pages/Tenancy/CompanySettings.php` | dispatch `refresh-topbar` after save | 2 |
| `app/Models/Company.php` | `initials()` | 3 |
| `lang/de/company.php`, `lang/de/layout.php` | labels | 2, 3 |
| `tests/Feature/TopbarTrailTest.php` | trail per page, escaping, re-render (create) | 2 |
| `tests/Feature/CompanyPickerTest.php` | dropdown entries, visibility, avatars | 3 |
| `tests/Feature/CompanyTest.php` | initials | 3 |
| `README.md`, specs, Outline | docs | 5 |

---

### Task 1: Spike — handover, render hook, alignment (throwaway)

Only findings are committed, into this plan ("Spike findings" at the end) and into the spec where they change it.

**Files (temporary, reverted at the end):** `app/Livewire/Topbar.php`, `app/Providers/Filament/AdminPanelProvider.php`, `resources/views/filament/company-switcher.blade.php`.

**Interfaces:**
- Produces: a "Spike findings" section answering the three questions below, each with the mechanism that worked. Tasks 2–4 assume the "expected" answer; if a finding differs, the executor adjusts those tasks' code to the finding and records the change in the findings section before starting Task 2.

- [ ] **Step 1: Branch**

```bash
git switch -c feat/topbar-trail
```

- [ ] **Step 2: Question 1 — does `Livewire::current()` in the top bar's `mount()` return the page?**

Expected: yes. Livewire renders the page, then its layout *inside* the page's render-stack frame; the layout mounts the top bar, whose `mount()` runs before the top bar pushes itself onto the stack. Create a temporary `app/Livewire/Topbar.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use Filament\Livewire\Topbar as BaseTopbar;
use Livewire\Livewire;

class Topbar extends BaseTopbar
{
    public string $probe = '';

    public function mount(): void
    {
        $current = Livewire::current();
        $this->probe = $current === null ? 'null' : $current::class;
    }
}
```

Register it in `AdminPanelProvider::panel()` with `->topbarLivewireComponent(\App\Livewire\Topbar::class)` and, temporarily, a hook `->renderHook(PanelsRenderHook::TOPBAR_END, fn (): string => '<span data-probe>'.e(\Livewire\Livewire::current()?->probe ?? 'no-topbar').'</span>')`.

Run against the development app (log in as a throwaway user — Step 5 shows how) and read `/admin/<slug>/customers`: `data-probe` must print `App\Filament\Resources\Customers\Pages\ListCustomers`. That one output answers both Question 1 (mount sees the page) and Question 2 (a render hook inside the top bar sees the top bar through `Livewire::current()`).

If mount sees `null` or the top bar: fall back to a request-scoped holder filled from Livewire's `render` event — in `AppServiceProvider::boot()`, `\Livewire\on('render', function ($component) { if ($component instanceof \Filament\Pages\Page) { app(\App\Filament\CurrentPage::class)->page = $component; } });` with `$this->app->scoped(CurrentPage::class)` — and record it.

- [ ] **Step 3: Question 1b — does the property survive `refresh-topbar`, and is the tenant set on that Livewire request?**

In the browser console on a company page: `Livewire.dispatch('refresh-topbar')`. Expected: `data-probe` still shows the page class, and the switcher still shows the company (Filament applies the panel's tenant middleware to Livewire updates). If the switcher disappears, record it — Task 2's `refresh-topbar` dispatch after a company rename would then need a redirect instead (`CompanySettings::getRedirectUrl()` returning its own URL).

- [ ] **Step 4: Question 3 — alignment**

Temporarily add to the existing `STYLES_AFTER` string, and wrap the desktop switcher in the view in `<div data-topbar-column><div>…</div></div>`:

```css
@media (min-width: 64rem) {
    .fi-body-has-navigation .fi-topbar { position: relative }
    .fi-body-has-navigation .fi-topbar-end { position: relative; z-index: 1 }
    .fi-body-has-navigation [data-topbar-column] { position: absolute; inset-block: 0; inset-inline: var(--sidebar-width) 0; display: flex; pointer-events: none }
    .fi-body-has-navigation [data-topbar-column] > div { display: flex; align-items: center; width: 100%; max-width: 80rem; margin-inline: auto; padding-inline: 2rem 5rem; pointer-events: none }
    .fi-body-has-navigation [data-topbar-column] > div > * { pointer-events: auto }
}
```

Screenshot `/admin/<slug>/customers` at 1280 and 1920 px (Step 5). Expected: the left edge of the switcher's avatar and of the "Kunden" heading are within 2 px of each other at both widths, and clicking the user menu at 1920 px still opens it.

- [ ] **Step 5: Screenshot tooling**

No browser is installed on the host; run Playwright in its container (arm64 images exist). Create the throwaway user and a company in the dev database:

```bash
docker compose run --rm app php artisan tinker --execute '
$u = App\Models\User::factory()->create(["email" => "trail-spike@example.test", "password" => "trail-spike-pw"]);
$c = App\Models\Company::factory()->create(["name" => "Kranz Ingenieurbüro GmbH"]);
$u->companies()->attach($c);
App\Models\Customer::factory()->for($c)->create(["name" => "Bauer & Kollegen GmbH"]);
echo $c->slug;'
```

Scratchpad `shot.py` (session scratchpad, not the repo):

```python
import sys
from playwright.sync_api import sync_playwright

base, email, pw, out, width = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4], int(sys.argv[5])
paths = sys.argv[6].split(",")
dark = len(sys.argv) > 7 and sys.argv[7] == "dark"
with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": width, "height": 900}, color_scheme="dark" if dark else "light")
    pg.goto(base + "/admin/login")
    pg.fill("input[type=email]", email)
    pg.fill("input[type=password]", pw)
    pg.click("button[type=submit]")
    pg.wait_for_url("**/admin**")
    for i, path in enumerate(paths):
        pg.goto(base + path)
        pg.wait_for_load_state("networkidle")
        pg.screenshot(path=f"{out}-{i}.png")
        if pg.locator("[data-company-switcher] button").first.is_visible():
            pg.locator("[data-company-switcher] button").first.click()
            pg.wait_for_timeout(300)
            pg.screenshot(path=f"{out}-{i}-open.png")
    b.close()
```

Run (from the scratchpad directory):

```bash
docker run --rm --network host -v "$PWD":/w -w /w mcr.microsoft.com/playwright/python:v1.55.0-noble \
  sh -c 'pip install -q playwright==1.55.0 && python shot.py http://localhost:8080 trail-spike@example.test trail-spike-pw spike 1280 /admin/<slug>/customers'
```

Read every PNG. Filament throttles five logins a minute per IP: leave 60 s between runs.

- [ ] **Step 6: Record findings, revert, commit**

Append "Spike findings" to this plan (the three answers, the mechanism used for each, screenshot file names). If the handover mechanism differs from the spec's §3 wording (the spec names a "request-scoped holder"; `Livewire::current()` needs none), correct spec §3 item 2 to what was found. Then:

```bash
git checkout -- app resources
rm -f app/Livewire/Topbar.php
git add docs/superpowers/plans/2026-09-26-topbar-trail.md docs/superpowers/specs/2026-09-26-topbar-trail-design.md
git commit -m "docs: record the top-bar trail spike findings

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

Keep the throwaway user and company until Task 4's screenshots are done; Task 4 deletes them.

---

### Task 2: The trail in the top bar

**Files:**
- Create: `app/Filament/Breadcrumbs.php`, `app/Livewire/Topbar.php`, `resources/views/filament/topbar-styles.blade.php`, `lang/de/layout.php`, `tests/Feature/TopbarTrailTest.php`
- Rename: `resources/views/filament/company-switcher.blade.php` → `resources/views/filament/topbar-trail.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`, `app/Filament/Pages/Tenancy/CompanySettings.php`

**Interfaces:**
- Produces:
  - `App\Filament\Breadcrumbs::for(Filament\Pages\Page $page): list<array{label: string, url: string|null}>` — the trail after the switcher; last entry has `url === null`.
  - `App\Livewire\Topbar` with `#[Locked] public array $trail` (same shape) and `mount(?array $trail = null)`.
  - View `filament.topbar-trail` taking `companies`, `current`, `trail`, `variant` (`'desktop'|'phone'`). The trail is `<ol data-topbar-trail>` of `<li>`; a linked crumb is `<a href>`, the last is `<span aria-current="page">`.
  - View `filament.topbar-styles` (no data), rendered at `STYLES_AFTER`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/TopbarTrailTest.php`:

```php
<?php

declare(strict_types=1);

use App\Filament\Pages\Tenancy\CompanySettings;
use App\Livewire\Topbar;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The crumbs after the switcher, as [label, url|null] pairs, read from the
 * top bar's <ol data-topbar-trail> — so a crumb elsewhere on the page (the
 * heading, a table cell) cannot satisfy an assertion.
 *
 * @return list<array{0: string, 1: string|null}>
 */
function trailOf(string $html): array
{
    $document = Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $trail = $document->querySelector('ol[data-topbar-trail]');

    if ($trail === null) {
        return [];
    }

    $crumbs = [];

    foreach ($trail->querySelectorAll('li') as $item) {
        $link = $item->querySelector('a');
        $crumbs[] = [trim((string) $item->textContent), $link?->getAttribute('href')];
    }

    return $crumbs;
}

/**
 * Acme GmbH and a member of it. A function rather than beforeEach properties:
 * Larastan analyses the tests too, and dynamic properties on TestCase fail it.
 *
 * @return array{0: Company, 1: User}
 */
function acme(): array
{
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    return [$company, memberOf($company)];
}

it('shows the page title alone on pages outside a resource', function (string $path, string $label): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $html = (string) $this->actingAs($user)->get($path)->assertOk()->getContent();

    expect(trailOf($html))->toBe([[$label, null]]);
})->with([
    'dashboard' => ['/admin/acme-gmbh', 'Dashboard'],
    'settings' => ['/admin/acme-gmbh/settings', 'Firmendaten'],
]);

it('shows the section alone on the customer list, without Filament\'s Übersicht', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers')->assertOk()->getContent();

    expect(trailOf($html))->toBe([['Kunden', null]]);
});

it('names a new customer after the page title, not Erstellen', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/create')->assertOk()->getContent();

    expect(trailOf($html))->toBe([
        ['Kunden', url('/admin/acme-gmbh/customers')],
        ['Neuer Kunde', null],
    ]);
});

it('ends on the customer on its page, without Ansehen', function (): void {
    /** @var TestCase $this */
    [$company, $user] = acme();
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001')->assertOk()->getContent();

    expect(trailOf($html))->toBe([
        ['Kunden', url('/admin/acme-gmbh/customers')],
        ['Bauer & Kollegen GmbH', null],
    ]);
});

it('links the customer from its edit page and ends on Bearbeiten', function (): void {
    /** @var TestCase $this */
    [$company, $user] = acme();
    Customer::factory()->for($company)->create(['name' => 'Bauer & Kollegen GmbH']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001/edit')->assertOk()->getContent();

    expect(trailOf($html))->toBe([
        ['Kunden', url('/admin/acme-gmbh/customers')],
        ['Bauer & Kollegen GmbH', url('/admin/acme-gmbh/customers/K-0001')],
        ['Bearbeiten', null],
    ]);
});

it('shows a deactivated customer by its plain name, without the badge', function (): void {
    /** @var TestCase $this */
    // Review focus 1: the heading is an HtmlString carrying a badge; the
    // trail takes the record title, which must stay plain text.
    [$company, $user] = acme();
    Customer::factory()->for($company)->archived()->create(['name' => 'Weber Haustechnik e.K.']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001')->assertOk()->getContent();

    expect(trailOf($html))->toBe([
        ['Kunden', url('/admin/acme-gmbh/customers')],
        ['Weber Haustechnik e.K.', null],
    ]);
});

it('escapes a customer name in the trail exactly once', function (): void {
    /** @var TestCase $this */
    // Review focus 2.
    [$company, $user] = acme();
    Customer::factory()->for($company)->create(['name' => 'Bauer & <Söhne> GmbH']);

    $html = (string) $this->actingAs($user)->get('/admin/acme-gmbh/customers/K-0001')->assertOk()->getContent();
    $trail = (string) str($html)->after('<ol data-topbar-trail')->before('</ol>');

    expect($trail)->toContain('Bauer &amp; &lt;Söhne&gt; GmbH')
        ->not->toContain('<Söhne>')
        ->not->toContain('&amp;amp;');
});

it('draws no breadcrumbs above the page heading', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $this->actingAs($user)->get('/admin/acme-gmbh/customers')->assertOk()
        ->assertDontSeeHtml('fi-breadcrumbs');
});

it('draws no trail on the company picker', function (): void {
    /** @var TestCase $this */
    [, $user] = acme();

    $this->actingAs($user)->get('/admin')->assertOk()
        ->assertDontSeeHtml('<ol data-topbar-trail');
});

it('keeps its trail when the top bar re-renders on its own', function (): void {
    // Fails if the trail were read from the current page at render time: on
    // a refresh-topbar request the only component rendering is the top bar.
    [$company] = acme();
    actInCompany($company);

    Livewire::test(Topbar::class, ['trail' => [['label' => 'Kunden', 'url' => null]]])
        ->dispatch('refresh-topbar')
        ->assertSeeHtml('<span aria-current="page">Kunden</span>');
});

it('refreshes the top bar after the company data is saved, so a new name shows', function (): void {
    [$company] = acme();
    actInCompany($company);

    Livewire::test(CompanySettings::class)
        ->fillForm(['name' => 'Acme Neu GmbH'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertDispatched('refresh-topbar');
});
```

`memberOf()` and `actInCompany()` come from `tests/Pest.php`. `Customer::factory()->archived()` — check `database/factories/CustomerFactory.php`; if the state has another name, use it.

- [ ] **Step 2: Run them to see them fail**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/TopbarTrailTest.php`
Expected: FAIL — `trailOf()` returns `[]` (no `<ol data-topbar-trail>`), `Class "App\Livewire\Topbar" not found`, `fi-breadcrumbs` present, `refresh-topbar` not dispatched.

- [ ] **Step 3: The trail builder**

`app/Filament/Breadcrumbs.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Pages\Page;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The crumbs the top bar shows after the company switcher (top-bar trail
 * spec §2.2): section, then record, then the page where it is not the record
 * itself. The last crumb is the current page and carries no link.
 *
 * Built from Filament's own breadcrumbs, so nested resources and clusters
 * come along for free. Filament ends every resource trail with a word for the
 * page — "Übersicht" on a list, "Ansehen" on a record — that repeats the crumb
 * before it; those are dropped. "Erstellen" gives way to the page title, which
 * says what is being created ("Neuer Kunde").
 */
final class Breadcrumbs
{
    /**
     * @return list<array{label: string, url: string|null}>
     */
    public static function for(Page $page): array
    {
        $crumbs = $page->getBreadcrumbs();

        if ($crumbs === []) {
            return [['label' => self::text($page->getTitle()), 'url' => null]];
        }

        if ($page instanceof ListRecords || $page instanceof ViewRecord) {
            array_pop($crumbs);
        } elseif ($page instanceof CreateRecord) {
            array_pop($crumbs);
            $crumbs[] = self::text($page->getTitle());
        }

        $trail = [];

        foreach ($crumbs as $url => $label) {
            $trail[] = ['label' => self::text($label), 'url' => is_string($url) ? $url : null];
        }

        // The current page is never a link, whatever Filament keyed it by.
        $trail[array_key_last($trail)]['url'] = null;

        return $trail;
    }

    /**
     * Plain text: Blade escapes the trail when it prints it, so a label must
     * not arrive already escaped.
     */
    private static function text(string|Htmlable $label): string
    {
        return $label instanceof Htmlable
            ? html_entity_decode(strip_tags($label->toHtml()), ENT_QUOTES | ENT_HTML5)
            : $label;
    }
}
```

- [ ] **Step 4: The top bar component**

`app/Livewire/Topbar.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Breadcrumbs;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar as BaseTopbar;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Livewire;

/**
 * Filament's top bar, holding the page's trail (top-bar trail spec §3).
 *
 * The top bar is its own Livewire component and knows nothing of the page.
 * It is mounted while the page's layout renders, so at mount the component
 * Livewire is rendering is still the page (spike findings); the trail is
 * taken from it then and kept as a property, because on the top bar's own
 * re-renders (`refresh-topbar`) there is no page to ask.
 *
 * Locked: the trail is rendered as links, and a client that could rewrite it
 * could point them anywhere.
 */
class Topbar extends BaseTopbar
{
    /** @var list<array{label: string, url: string|null}> */
    #[Locked]
    public array $trail = [];

    /**
     * @param  list<array{label: string, url: string|null}>|null  $trail  given by tests; the page's otherwise
     */
    public function mount(?array $trail = null): void
    {
        $page = Livewire::current();

        // Outside a company — the picker on /admin — there is no trail.
        $this->trail = $trail ?? (($page instanceof Page && Filament::getTenant() !== null)
            ? Breadcrumbs::for($page)
            : []);
    }
}
```

- [ ] **Step 5: Rename the view and draw the trail**

```bash
git mv resources/views/filament/company-switcher.blade.php resources/views/filament/topbar-trail.blade.php
```

Replace the file's opening comment and wrap its content so it reads (dropdown body unchanged in this task):

```blade
{{--
    The top bar's company switcher and, after it, the trail of the page
    (top-bar trail spec §2). The switcher is the trail's first crumb.

    Rendered twice (company-picker spike findings): after the brand for
    desktop — Filament hides that whole area below 64rem — and before the
    user menu for phones, hidden from 64rem by topbar-styles. The phone copy
    leaves out the fi-tenant-menu class, which Filament hides in the top bar
    below 64rem, shows only the avatar, and carries no trail: beside the user
    menu there is no room for one.

    On desktop the whole of it sits in data-topbar-column, which
    topbar-styles lays over the page's content column, so the switcher starts
    where the heading below it starts.
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Company> $companies */
    /** @var \App\Models\Company|null $current */
    /** @var list<array{label: string, url: string|null}> $trail */
@endphp

@if ($companies->isNotEmpty())
    <div @if ($variant === 'desktop') data-topbar-column @endif>
        <div class="app-topbar-trail">
            <div data-company-switcher="{{ $variant }}">
                {{-- … the existing <x-filament::dropdown> … unchanged … --}}
            </div>

            @if ($trail !== [])
                <ol data-topbar-trail class="app-trail" aria-label="{{ __('layout.trail') }}">
                    @foreach ($trail as $crumb)
                        <li class="app-trail-item">
                            <x-filament::icon icon="heroicon-m-chevron-right" class="app-trail-separator" />
                            @if ($crumb['url'] !== null)
                                <a href="{{ $crumb['url'] }}" class="app-trail-link">{{ $crumb['label'] }}</a>
                            @else
                                <span aria-current="page">{{ $crumb['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
@endif
```

Keep `<span aria-current="page">{{ $crumb['label'] }}</span>` on one line exactly as written — the re-render test matches it literally.

`lang/de/layout.php`:

```php
<?php

declare(strict_types=1);

return [
    'trail' => 'Navigationspfad',
];
```

- [ ] **Step 6: Styles as a view**

`resources/views/filament/topbar-styles.blade.php` — takes over the phone rule from the provider and styles the trail:

```blade
{{--
    The top bar's own CSS. There is no frontend build (CLAUDE.md), so only
    classes in Filament's shipped CSS exist; everything the trail and the
    switcher need beyond those is here, injected at STYLES_AFTER. Filament
    marks dark mode with the dark class on <html>.
--}}
<style>
    /* Filament's shipped CSS has no global responsive-hide class
       (company-picker spike findings). */
    @media (min-width: 64rem) { [data-company-switcher="phone"] { display: none } }

    .app-topbar-trail { display: flex; align-items: center; gap: 0.25rem; min-width: 0 }
    .app-trail { display: flex; align-items: center; gap: 0.25rem; min-width: 0; font-size: 0.875rem; font-weight: 500 }
    .app-trail-item { display: flex; align-items: center; gap: 0.25rem; min-width: 0; white-space: nowrap }
    .app-trail-item > span, .app-trail-link { overflow: hidden; text-overflow: ellipsis }
    .app-trail-separator { width: 1rem; height: 1rem; flex-shrink: 0; color: var(--gray-400) }
    .app-trail-link { color: var(--gray-500) }
    .app-trail-link:hover { color: var(--gray-700) }
    .app-trail-item > span[aria-current] { color: var(--gray-950) }
    .dark .app-trail-separator { color: var(--gray-500) }
    .dark .app-trail-link { color: var(--gray-400) }
    .dark .app-trail-link:hover { color: var(--gray-200) }
    .dark .app-trail-item > span[aria-current] { color: var(--color-white) }
</style>
```

- [ ] **Step 7: Panel configuration**

In `AdminPanelProvider::panel()`:

1. After `->tenantMenu(false)` add:

```php
            // The trail lives in the top bar, after the company switcher
            // (top-bar trail spec §2.1); the page draws none above its heading.
            ->breadcrumbs(false)
            ->topbarLivewireComponent(Topbar::class)
```

2. Replace the `STYLES_AFTER` hook line with:

```php
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn (): string => view('filament.topbar-styles')->render())
```

and update the comment above the two switcher hooks: "hidden from 64rem by the one rule below" → "hidden from 64rem by topbar-styles".

3. Replace `switcher()`:

```php
    /**
     * The trail comes from the top bar, which is the component rendering when
     * this hook runs (spike findings). The phone copy draws none.
     */
    private function switcher(string $variant): string
    {
        $topbar = Livewire::current();

        return view('filament.topbar-trail', [
            'companies' => SelectCompany::getCompanies(),
            'current' => Filament::getTenant(),
            'trail' => $variant === 'desktop' && $topbar instanceof Topbar ? $topbar->trail : [],
            'variant' => $variant,
        ])->render();
    }
```

Imports: `use App\Livewire\Topbar;` and `use Livewire\Livewire;`.

- [ ] **Step 8: Refresh the top bar after the company data is saved**

In `CompanySettings`, add:

```php
    /**
     * The switcher in the top bar names the company, and the top bar does not
     * re-render with the page. Without this a rename shows in the form and
     * nowhere else until the next navigation.
     */
    protected function afterSave(): void
    {
        $this->dispatch('refresh-topbar');
    }
```

(`EditTenantProfile::save()` calls `callHook('afterSave')`.)

- [ ] **Step 9: Run the new tests, then the suite**

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/TopbarTrailTest.php`
Expected: PASS.

Run: `docker compose run --rm app ./vendor/bin/pest`
Expected: PASS. `CompanyPickerTest`'s `switcherMarkup()` reads from `<div data-company-switcher="desktop"` to `</nav>`, which now includes the trail; on `/admin` there is none, and inside a company the trail holds page titles only, so its assertions are unaffected. If one fails, read why before touching it.

- [ ] **Step 10: Checks and commit**

```bash
docker compose run --rm app sh -c './vendor/bin/rector process && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress && ./vendor/bin/pest'
git add -A app resources lang tests
git commit -m "feat: show the page's trail after the company switcher in the top bar

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: The dropdown — label, initials, ✓, Firmen verwalten, Neue Firma

**Files:**
- Modify: `app/Models/Company.php`, `resources/views/filament/topbar-trail.blade.php`, `resources/views/filament/topbar-styles.blade.php`, `lang/de/company.php`
- Test: `tests/Feature/CompanyTest.php`, `tests/Feature/CompanyPickerTest.php`

**Interfaces:**
- Consumes: view `filament.topbar-trail` and its `companies`/`current`/`variant` data (Task 2).
- Produces: `Company::initials(): string`; `data-current-company` stays the marker of the current company in the list.

- [ ] **Step 1: Failing initials tests**

Append to `tests/Feature/CompanyTest.php`:

```php
it('draws initials from the first letters of the first two words', function (string $name, string $initials): void {
    // Fails with byte-wise substr, which cuts "Ü" in half, and with a split on
    // spaces alone, which makes "(Neu)" start with a parenthesis.
    expect(Company::factory()->make(['name' => $name])->initials())->toBe($initials);
})->with([
    'two words and a legal form' => ['Kranz Ingenieurbüro GmbH', 'KI'],
    'umlaut first' => ['Übersee Handel', 'ÜH'],
    'lower case' => ['bauer & kollegen', 'BK'],
    'one word' => ['Balt', 'B'],
    'punctuation first' => ['(Neu) Handel', 'NH'],
    'digits' => ['3D Druck GmbH', '3D'],
    'no letters at all' => ['!!!', '!'],
]);
```

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyTest.php --filter=initials`
Expected: FAIL — `Call to undefined method App\Models\Company::initials()`.

- [ ] **Step 2: `Company::initials()`**

In `app/Models/Company.php`, after `isArchived()`:

```php
    /**
     * Up to two letters for the company's avatar: the first character of each
     * of the name's first two words (top-bar trail spec §2.6). Words are runs
     * of letters and digits, so "(Neu) Handel" gives "NH"; characters, not
     * bytes, so "Übersee" keeps its "Ü". A name with no letter or digit at all
     * falls back to its first character, so the avatar is never empty.
     */
    public function initials(): string
    {
        $name = trim((string) $this->name);
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($words === []) {
            return mb_substr($name, 0, 1);
        }

        $letters = array_map(
            fn (string $word): string => mb_substr($word, 0, 1),
            array_slice($words, 0, 2),
        );

        return mb_strtoupper(implode('', $letters));
    }
```

Run the filter again. Expected: PASS.

- [ ] **Step 3: Failing dropdown tests**

In `tests/Feature/CompanyPickerTest.php`:

1. In the test that lists the user's active companies on `/admin` (it ends with `expect($switcher)->not->toContain('Neue Firma');`), replace the comment `// Only switches: none of the old menu entries.` and the two `not->toContain` lines with:

```php
    // Top-bar trail spec §2.5: the label, then the companies, then Neue Firma.
    // Firmen verwalten would link to this very page, so it is left out here.
    expect($switcher)->toContain('Firma wechseln')
        ->toContain('href="'.url('/admin/new').'"')
        ->toContain('Neue Firma');
    expect($switcher)->not->toContain('Firmen verwalten');
    expect($switcher)->not->toContain('Firmendaten');
```

2. Replace the test `'shows no switcher inside an archived company when no company is active'` with:

```php
it('shows the switcher inside an archived company even with no active company', function (): void {
    /** @var TestCase $this */
    // Review focus 4. Top-bar trail spec §2.5: the dropdown always has
    // Firmen verwalten and Neue Firma now, so it is never empty.
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->archived()->create(['name' => 'Gone GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/gone-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Gone GmbH')
        ->toContain('href="'.url('/admin').'"')
        ->toContain('Firmen verwalten')
        ->toContain('href="'.url('/admin/new').'"');
    expect($switcher)->not->toContain('data-current-company');
    expect($switcher)->not->toContain('Firma wechseln');
});
```

3. Add:

```php
it('offers Firmen verwalten and Neue Firma inside a company, and marks the current one', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $user->companies()->attach(Company::factory()->create(['name' => 'Kranz Ingenieurbüro GmbH']));
    $user->companies()->attach(Company::factory()->create(['name' => 'Hofgarten Immobilien GmbH']));

    $switcher = switcherMarkup((string) $this->actingAs($user)->get('/admin/kranz-ingenieurburo-gmbh')->assertOk()->getContent());

    expect($switcher)->toContain('Firma wechseln')
        ->toContain('href="'.url('/admin').'"')
        ->toContain('Firmen verwalten')
        ->toContain('href="'.url('/admin/new').'"')
        ->toContain('>KI<')
        ->toContain('>HI<');
    // Exactly one company is marked: the one in the URL.
    expect(substr_count($switcher, 'data-current-company'))->toBe(1);
});

it('draws company avatars locally, never from ui-avatars.com', function (): void {
    /** @var TestCase $this */
    $company = Company::factory()->create(['name' => 'Acme GmbH']);

    $this->actingAs(memberOf($company))->get('/admin/acme-gmbh')->assertOk()
        ->assertDontSee('ui-avatars.com');
});
```

Check the slug for "Kranz Ingenieurbüro GmbH": `Str::slug()` gives `kranz-ingenieurburo-gmbh`; if the run shows another, use it.

4. The existing `'hides the switcher from a user with no active company'` on `/admin` stays as it is — that is still the one place the switcher is hidden (spec §2.3).

Run: `docker compose run --rm app ./vendor/bin/pest tests/Feature/CompanyPickerTest.php`
Expected: FAIL on the new and changed tests ("Firma wechseln", "Firmen verwalten", initials, the archived-company switcher, ui-avatars).

- [ ] **Step 4: Labels**

In `lang/de/company.php`, after `'picker'`:

```php
    'switcher' => [
        'label' => 'Firma wechseln',
        'manage' => 'Firmen verwalten',
    ],
```

- [ ] **Step 5: The dropdown**

In `topbar-trail.blade.php`:

1. The outer condition becomes `@if ($companies->isNotEmpty() || $current !== null)`, with the comment above it:

```blade
{{-- Hidden only where there is nothing to switch to and no company to name:
     on /admin with no active company (spec §2.3). Inside an archived company
     opened by its URL it shows, because Firmen verwalten and Neue Firma make
     the dropdown worth opening (spec §2.5). --}}
```

2. Replace the whole `<x-filament::dropdown>…</x-filament::dropdown>` with:

```blade
<x-filament::dropdown placement="bottom-start" size @class(['fi-tenant-menu' => $variant === 'desktop'])>
    <x-slot name="trigger">
        <button type="button" class="fi-tenant-menu-trigger" aria-label="{{ $current?->name ?? __('company.picker.title') }}">
            <span class="app-company-avatar">
                @if ($current)
                    {{ $current->initials() }}
                @else
                    {{-- Inline style: the trigger's .fi-icon rule would push a
                         bare icon to the end with margin-inline-start: auto. --}}
                    <x-filament::icon icon="heroicon-o-building-office-2" style="margin: 0" />
                @endif
            </span>
            @if ($variant === 'desktop')
                <span class="fi-tenant-menu-trigger-text">
                    <span class="fi-tenant-menu-trigger-tenant-name">
                        {{ $current?->name ?? __('company.picker.title') }}
                    </span>
                </span>
            @endif
            <x-filament::icon icon="heroicon-m-chevron-down" />
        </button>
    </x-slot>

    @if ($companies->isNotEmpty())
        <x-filament::dropdown.header class="app-company-menu-label">
            {{ __('company.switcher.label') }}
        </x-filament::dropdown.header>

        <x-filament::dropdown.list>
            @foreach ($companies as $company)
                @php($isCurrent = $current?->is($company) ?? false)
                <x-filament::dropdown.list.item
                    tag="a"
                    :href="filament()->getUrl($company)"
                    :color="$isCurrent ? 'primary' : 'gray'"
                    :data-current-company="$isCurrent"
                >
                    <span class="app-company-option">
                        <span @class(['app-company-avatar', 'app-company-avatar-current' => $isCurrent])>{{ $company->initials() }}</span>
                        <span class="app-company-option-name">{{ $company->name }}</span>
                        @if ($isCurrent)
                            <x-filament::icon icon="heroicon-m-check" class="app-company-option-check" />
                        @endif
                    </span>
                </x-filament::dropdown.list.item>
            @endforeach
        </x-filament::dropdown.list>
    @endif

    {{-- A second list: Filament draws the divider between lists. --}}
    <x-filament::dropdown.list>
        {{-- Left out on /admin, where it would link to the page itself. --}}
        @if ($current)
            <x-filament::dropdown.list.item tag="a" :href="filament()->getHomeUrl()" icon="heroicon-o-building-office-2">
                {{ __('company.switcher.manage') }}
            </x-filament::dropdown.list.item>
        @endif
        <x-filament::dropdown.list.item tag="a" :href="filament()->getTenantRegistrationUrl()" icon="heroicon-o-plus">
            {{ __('company.actions.create') }}
        </x-filament::dropdown.list.item>
    </x-filament::dropdown.list>
</x-filament::dropdown>
```

`:data-current-company="$isCurrent"` renders the attribute only when true (Blade drops `false` attributes) — the "exactly one" test depends on it. The avatar text `{{ $company->initials() }}` must sit directly between the tags (`>KI<`) as written.

- [ ] **Step 6: Avatar and option styles**

Append inside the `<style>` of `topbar-styles.blade.php`:

```css
    .app-company-avatar { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 1.75rem; height: 1.75rem; border-radius: 0.5rem; border: 1px solid var(--gray-200); background: var(--gray-50); color: var(--gray-600); font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.02em }
    .app-company-avatar-current { border-color: var(--primary-300); background: var(--primary-50); color: var(--primary-700) }
    .app-company-option { display: flex; align-items: center; gap: 0.75rem; width: 100% }
    .app-company-option-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis }
    .app-company-option-check { width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--primary-600) }
    .app-company-menu-label { font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; color: var(--gray-400) }
    .dark .app-company-avatar { border-color: var(--gray-700); background: var(--gray-800); color: var(--gray-300) }
    .dark .app-company-avatar-current { border-color: var(--primary-700); background: color-mix(in oklab, var(--primary-500) 15%, transparent); color: var(--primary-400) }
    .dark .app-company-option-check { color: var(--primary-400) }
```

- [ ] **Step 7: Run tests, checks, commit**

Run: `docker compose run --rm app ./vendor/bin/pest`
Expected: PASS.

```bash
docker compose run --rm app sh -c './vendor/bin/rector process && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress && ./vendor/bin/pest'
git add -A app resources lang tests
git commit -m "feat: label the switcher, mark the current company, add Firmen verwalten and Neue Firma

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Align the trail with the content, and look at it

**Files:**
- Modify: `resources/views/filament/topbar-styles.blade.php`

**Interfaces:**
- Consumes: `data-topbar-column` wrapper (Task 2), `--sidebar-width` (Filament sets it on the layout from the panel's sidebar width).

- [ ] **Step 1: Alignment rules**

Append inside the `<style>` (use the Task 1 findings if they changed any value):

```css
    /* Spec §3.1. From 64rem, with a sidebar, the column spans the width the
       page content spans — from the sidebar's edge to the right edge — and
       its box mirrors fi-main: 80rem wide at most, centred, 2rem inline
       padding. So the switcher starts where the heading starts, at any
       width. Positioned over the top bar rather than in its flow, because in
       the flow the user menu would narrow it and shift the centring.

       The column ignores the pointer except over its own content, and the
       user menu is lifted above it, so nothing under the column stops
       working. The end padding keeps a long trail clear of the user menu. */
    @media (min-width: 64rem) {
        .fi-body-has-navigation .fi-topbar { position: relative }
        .fi-body-has-navigation .fi-topbar-end { position: relative; z-index: 1 }
        .fi-body-has-navigation [data-topbar-column] { position: absolute; inset-block: 0; inset-inline: var(--sidebar-width) 0; display: flex; pointer-events: none }
        .fi-body-has-navigation [data-topbar-column] > .app-topbar-trail { width: 100%; max-width: 80rem; margin-inline: auto; padding-inline: 2rem 5rem; pointer-events: none }
        .fi-body-has-navigation [data-topbar-column] > .app-topbar-trail > * { pointer-events: auto }
    }
```

- [ ] **Step 2: Run the suite** (CSS only; nothing should move)

Run: `docker compose run --rm app ./vendor/bin/pest`
Expected: PASS.

- [ ] **Step 3: Screenshots**

With the Task 1 throwaway user (`trail-spike@example.test`), add a second company and an archived customer for the pictures:

```bash
docker compose run --rm app php artisan tinker --execute '
$u = App\Models\User::where("email", "trail-spike@example.test")->first();
$u->companies()->attach(App\Models\Company::factory()->create(["name" => "Hofgarten Immobilien GmbH"]));'
```

Run `shot.py` (Task 1 Step 5), leaving 60 s between runs, at 1280 and 1920 px, light and dark, and at 390 px light, for the paths
`/admin,/admin/<slug>,/admin/<slug>/settings,/admin/<slug>/customers,/admin/<slug>/customers/create,/admin/<slug>/customers/K-0001,/admin/<slug>/customers/K-0001/edit`.

Read every PNG and check:
- at 1280 and 1920: the switcher's avatar and the page heading share a left edge (≤ 2 px); the trail matches spec §2.2; no breadcrumbs above the heading;
- the dropdown (`-open` shots): "Firma wechseln", initials avatars, the current company amber with ✓, a divider, "Firmen verwalten", "Neue Firma"; on `/admin` no "Firmen verwalten";
- `/admin`: "Firma wählen ▾" directly after the logo;
- 390 px: avatar-only switcher before the user menu, no trail;
- dark: every crumb and avatar legible.

Review focus 5: at 1920 px, click the user menu trigger (add `pg.locator(".fi-user-menu-trigger").click(); pg.screenshot(...)` for one run) — the user menu must open.

- [ ] **Step 4: Remove the throwaway data**

```bash
docker compose run --rm app php artisan tinker --execute '
$u = App\Models\User::where("email", "trail-spike@example.test")->first();
foreach ($u->companies as $c) { $c->customers()->delete(); $c->users()->detach(); $c->delete(); }
$u->delete();'
```

Confirm no company named "Kranz Ingenieurbüro GmbH" or "Hofgarten Immobilien GmbH" remains that the owner did not create: `docker compose run --rm app php artisan tinker --execute 'echo App\Models\Company::pluck("name");'`.

- [ ] **Step 5: Commit**

```bash
docker compose run --rm app sh -c './vendor/bin/rector process && ./vendor/bin/pint && ./vendor/bin/phpstan analyse --memory-limit=512M --no-progress && ./vendor/bin/pest'
git add resources/views/filament/topbar-styles.blade.php
git commit -m "feat: align the top-bar trail with the page content

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Documents

**Files:**
- Modify: `README.md`, `docs/superpowers/specs/2026-09-25-company-picker-design.md`, and — if they say something this falsified — `CLAUDE.md`, `.ai/guidelines/**` (then regenerate `AGENTS.md` per `docs/agents-md-maintenance.md`), `docs/superpowers/specs/2026-09-23-invoice-system-design.md` §3.2
- Outline collection **Invoice**

- [ ] **Step 1: Company-picker spec**

Under its §2.1 heading, add:

```markdown
> **Revised 2026-09-26** by `2026-09-26-topbar-trail-design.md`: the dropdown
> now carries a "Firma wechseln" label, initials avatars and, under a divider,
> "Firmen verwalten" and "Neue Firma"; the switcher shows inside an archived
> company opened by its URL; and it is the first crumb of the page's trail.
> The bullets below are otherwise unchanged.
```

- [ ] **Step 2: README**

In "What it does today", rewrite the second bullet's switcher sentences to say what a user now sees: the switcher next to the logo is the start of a trail showing where you are (`Kranz Ingenieurbüro GmbH › Kunden › Bauer & Kollegen GmbH`), lined up with the page; its menu lists your companies with the current one ticked, and also takes you to the start page ("Firmen verwalten") or to a new company ("Neue Firma"). Describe behaviour, not classes. "Not built yet" is unaffected.

- [ ] **Step 3: Check the rest**

```bash
grep -rn -i "switcher\|breadcrumb\|Brotkrum\|nothing else\|only switches" CLAUDE.md .ai/guidelines docs/superpowers/specs/2026-09-23-invoice-system-design.md
```

Correct any claim that the switcher only switches or that breadcrumbs sit above the heading. If `.ai/guidelines` changes, regenerate `AGENTS.md` as `docs/agents-md-maintenance.md` says — never edit it directly.

- [ ] **Step 4: Outline**

Search the Invoice collection for "Switcher", "Umschalter", "Firma wählen", "Neue Firma" and "Brotkrümel"/"breadcrumb" (`mcp__outline__list_documents` / `fetch`). Patch each statement this change falsified — "Running It Locally" first — with `mcp__outline__update_document`.

- [ ] **Step 5: Commit**

```bash
git add -A README.md CLAUDE.md .ai AGENTS.md docs
git commit -m "docs: describe the top-bar trail, and correct what it falsified

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

## Spike findings

(Filled in by Task 1.)
