# Company Picker — Design

Date: 2026-09-25
Status: Approved in conversation; written spec awaiting review
Companion to: `2026-09-23-invoice-system-design.md`, which remains the authority
on the system as a whole, and `2026-09-24-companies-and-tenancy-design.md`,
whose tenancy backbone this builds on and partly revises.

## 1. Purpose

`/admin` becomes the place where the user sees every company they belong to and
chooses one. Until now it was not a page at all: Filament redirected it into the
user's default company, or — with no company — forced the user into company
registration.

A first attempt put the picker on a centred card in Filament's *simple* layout,
the one login uses. That was rejected: the picker must look like every other
screen of the panel. **Success is that `/admin` is visually the same page as
`/admin/{company}` — same top bar, same sidebar, same content area — with the
company tiles where the dashboard widgets would be.**

## 2. What the user sees

### 2.1 `/admin` — "Firma wählen"

The full panel layout, identical in structure to `/admin/{company}`:

- **Top bar:** brand, global search, user menu — unchanged (search: see §4.3).
- **Sidebar:** the same frame. Where the company menu sits, a placeholder menu
  reading **"Firma wählen ▾"** with a neutral icon. Its dropdown lists the user's
  active companies, each linking to `/admin/{slug}`, then **"Neue Firma"**
  linking to registration. Below it, **no navigation links**: every link in the
  sidebar belongs to a company, and none is chosen.
- **Content:** page heading "Firma wählen", a **"Neue Firma"** header action at
  the top right (Filament's place for page actions), and a grid of **tiles**.
  With no company, the header action is not shown — the empty state carries
  the same action, and the page would otherwise show it twice.
- **Tile:** company name, and beneath it the legal form as its German label
  (`GmbH`, `Einzelunternehmen`). Styled as Filament's section card — the same
  card the dashboard widgets use. The whole tile is a link to `/admin/{slug}`.
- **Which companies:** exactly `User::getTenants()` — the user's non-archived
  companies, ordered by name. Archived companies and other users' companies do
  not appear. Using the same method as the company menu means the two cannot
  disagree.
- **Browser title:** "Firma wählen".

The page is shown whatever the number of companies, so `/admin` always means the
same thing.

### 2.2 `/admin` with no active company

Same layout. In place of the tiles, an empty state — **"Noch keine Firma
angelegt."** — with a "Neue Firma" action. The placeholder menu contains only
"Neue Firma".

**Nothing redirects to registration.** A user creates a company by choosing to.
This applies to a brand-new user too: the first login lands on `/admin`, not on
`/admin/new`.

### 2.3 Getting there and back

- **Login lands on `/admin`.** A link the user was on their way to when asked to
  log in still wins (`redirect()->intended()`).
- **From inside a company**, the company menu carries an **"Alle Firmen"** entry
  leading to `/admin`, alongside the existing entries.
- **After registering** a company the user still lands on its settings page, as
  today (companies-and-tenancy §6).

### 2.4 The company dashboard, `/admin/{company}`

Filament's two default widgets ("Willkommen", and the Filament version card)
are removed. The dashboard is not left blank — an empty screen says what to do
next (`.ai/guidelines/ui/core.blade.php`) — so it shows one line:
**"Noch keine Inhalte. Firmendaten vervollständigen →"**, linking to
`/admin/{company}/settings`. It goes when invoicing gives the dashboard real
content.

### 2.5 Archiving the last company is allowed

companies-and-tenancy §5.4 refused archiving the user's last active company,
for one stated reason: it was a dead end, because Filament then forced every
navigation into registration and no screen outside a company existed to recover
from. Both halves of that are gone — `/admin` always renders and never forces
registration — so the guard has no reason left, and **it is removed**. Archiving
the last company leaves the user on `/admin` with the empty state of §2.2.

One consequence: the companies list, where a company is restored, lives inside
a company, so with none active it is reachable only through an archived
company's URL, which keeps working because `canAccessTenant()` is unchanged.
That is accepted for now; §7 records it.

An already archived company offers no archive action, and archiving it again
leaves its archive date alone. `canBeArchived()`, which existed only to carry
the last-company condition, goes with it, as do its row lock and
`CannotArchiveLastCompany`.

## 3. Components

Each unit has one job.

1. **`SelectCompany` — the `/admin` page.** A full-layout Filament `Page`,
   replacing the simple-layout version. `$isDiscovered = false`, so page
   discovery does not also register it under every company. It supplies the
   tiles, the empty state and the "Neue Firma" header action. It never
   redirects.

2. **Serving it on `/admin`.** Filament registers `GET /admin`
   (`RedirectToTenantController`) after any application route, and the later
   registration wins, so a competing route cannot take it over. Its action is
   resolved from the container, and a Livewire page is invokable, so
   `AppServiceProvider` binds `RedirectToTenantController` to `SelectCompany`.
   The route keeps its name, path and middleware.

3. **The panel's no-company state**, configured in `AdminPanelProvider`:
   - **Company menu:** `->tenantMenu(fn (): bool => filament()->getTenant() !== null)`.
     Filament's menu cannot render without a tenant — it passes the null tenant
     to `getTenantName()`, which requires a model.
   - **Placeholder menu:** a render hook at the top of the sidebar navigation
     renders `CompanyPickerMenu` only when there is no tenant.
   - **Navigation:** built empty when there is no tenant. `navigation(false)` is
     not an option — Filament then drops the sidebar entirely. Every navigation
     URL needs a tenant, so building it without one would throw.

4. **`CompanyPickerMenu` — the placeholder menu.** A Blade view using Filament's
   dropdown components, so it is styled by Filament's shipped CSS. Lists
   `getTenants()` and "Neue Firma".

5. **`Dashboard` — the application's dashboard.** A subclass of Filament's
   dashboard with no widgets, rendering the hint of §2.4. Replaces
   `Filament\Pages\Dashboard` in the panel; `AccountWidget` and
   `FilamentInfoWidget` are removed from the panel.

6. **Kept from the first attempt:** the controller binding, `LoginResponse`
   (login → `/admin`), and the "Alle Firmen" company-menu entry.

7. **`Company::archive()`** loses the last-company guard (§2.5).

No Filament view is published or overridden. Everything goes through
configuration, render hooks and subclasses, so a Filament upgrade cannot
silently diverge from a forked copy.

**Styling constraint.** There is no frontend build (`CLAUDE.md`), so a Tailwind
class Filament's pre-built CSS does not already contain is never compiled. The
tiles and the placeholder menu use Filament components; the tile grid, if no
shipped class fits, uses an inline style.

## 4. Spike first

Three things cannot be settled by reading code, and the implementation plan
opens with a throwaway spike, checked by screenshot, that answers them before
anything is built on them. If one cannot be done cleanly, work stops and the
question goes back to the user rather than being worked around.

### 4.1 Placeholder menu placement

Does a render hook place the placeholder menu where Filament's company menu
sits — and look the same? Candidate hooks: `SIDEBAR_NAV_START`,
`SIDEBAR_LOGO_AFTER`.

### 4.2 Empty navigation without a tenant

The cleanest way to build no navigation links only while there is no tenant,
without removing the sidebar. Candidates: a navigation builder closure that
returns nothing when there is no tenant; per-page `shouldRegisterNavigation()`.

### 4.3 Global search without a tenant

Does the top-bar search work at `/admin`? If it errors, it is hidden on this one
page, and that is recorded in this spec as a deviation from "identical layout".

### 4.4 What the spike found (2026-09-25)

- **Placement:** the `SIDEBAR_START` render hook, wrapped in
  `fi-sidebar-header-controls`, lands in the exact box Filament's company menu
  occupies on desktop; `SIDEBAR_NAV_START` inherits the nav's padding and
  scrollbar gutter. One deviation: in the phone-width drawer the menu sits above
  the sidebar's logo header rather than below it.
- **Navigation:** a navigation closure returning an empty `NavigationBuilder`
  when there is no tenant keeps the sidebar and drops its links.
- **Search:** it errored — a company result linked to the companies list, whose
  route needs a tenant. Not hidden: a company result now links to that
  company's settings with the company as the tenant, so search works on
  `/admin` as everywhere else.

## 5. Testing

Pest against PostgreSQL. Each test is chosen for what would make it fail.

- **The layout is the full one:** `GET /admin` is 200 and contains the sidebar
  and top bar (`fi-sidebar`, `fi-topbar`). The simple-layout attempt fails this.
- **Tiles:** the user's companies, in name order, each with its German
  legal-form label; each linking to `/admin/{slug}`; archived and other users'
  companies absent.
- **Placeholder menu:** at `/admin` the sidebar contains "Firma wählen", the
  companies and "Neue Firma", and not Filament's company menu; at
  `/admin/{company}` the reverse.
- **No navigation without a company:** at `/admin`, no `/admin/{slug}/…` link in
  the sidebar navigation.
- **No forced registration:** a user with no companies gets **200** at `/admin`
  — not a redirect — with "Noch keine Firma angelegt." and a link to
  `/admin/new`. This replaces the existing test asserting the redirect.
- **Login** lands on `/admin`; **"Alle Firmen"** appears inside a company.
- **Dashboard:** `/admin/{company}` shows no "Willkommen" and shows the hint,
  linking to `/admin/{company}/settings`.
- **Archiving:** archiving the last active company now succeeds — the old
  refusal test is inverted rather than deleted, so the removal is itself
  asserted. Archiving an already archived company is still refused.

**Test data.** Tests never read the development database. Pest runs against
`invoice_test`, rebuilt on every run; every company a test needs is created in
the test with `Company::factory()`. Nothing is seeded.

**Screenshots** — rendered pages need eyes (`CLAUDE.md`). Taken with a headless
browser in a throwaway Playwright container against the development database,
light and dark: `/admin` with two companies, `/admin` with none, and
`/admin/{company}`, compared side by side with the owner's existing company
page. The owner's account and companies are only viewed, never changed. The two
views that need other data use two throwaway users — `picker-two@example.test`
with two demo companies, `picker-none@example.test` with none — created before
the run and deleted, with their companies, after it.

## 6. Documents this change falsifies

- **`README.md`** — rewrite the picker feature entry; the "Companies are
  deactivated" entry, which says archiving the last company is refused; and the
  first-run note that the first login lands on registration.
- **`CLAUDE.md`** — check for any claim about forced registration or the
  last-company guard.
- **`2026-09-23-invoice-system-design.md` §3.2** — rewrite the 2026-09-25
  correction note to describe the full-layout picker and the absence of forced
  registration.
- **`2026-09-24-companies-and-tenancy-design.md`** — **not edited.** Its §10
  says it is dated and not updated when the stack moves. The correction note the
  first attempt added to its §6 is removed again; this spec records what changed
  in its §5.4 and §6 instead.
- **Outline, collection "Invoice"** — "Domain Model" and "Running It Locally"
  (both patched for the first attempt, and now wrong again), plus a search for
  any page describing forced registration or the last-company guard.

## 7. Out of scope, and handed forward

- **Restoring an archived company when none is active.** The companies list
  lives inside a company, so with no active company it is reachable only through
  an archived company's URL. Acceptable at one user; a "show archived" affordance
  on `/admin` is the fix when it is needed, not before.
- **Dashboard content** — arrives with invoicing.
- **Roles and multi-user** — unchanged; the picker lists the join table's
  companies for the acting user and nothing more.

## 8. Decisions made during this design

| Decision | Choice |
|---|---|
| Where the tiles live | On `/admin`, outside any company |
| `/admin` layout | The full panel layout, not the simple one |
| One company | Picker still shown; `/admin` never skips ahead |
| Tile content | Name, legal form beneath |
| Sidebar without a company | Placeholder "Firma wählen" menu, no navigation links |
| Creating a company | "Neue Firma" header action and menu entry; never forced |
| No companies | Empty state on `/admin`; no redirect to registration |
| After login | `/admin`, unless an intended URL is pending |
| Company dashboard | Default widgets removed; one hint line to settings |
| Last-company archive guard | Removed — its dead end no longer exists |
| Mechanism | Unbound page + panel config + render hook; no forked Filament views |
