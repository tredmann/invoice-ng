# Company Picker — Design

Date: 2026-09-25
Status: Revised after the owner reviewed the built version; revision awaiting review
Companion to: `2026-09-23-invoice-system-design.md`, which remains the authority
on the system as a whole, and `2026-09-24-companies-and-tenancy-design.md`,
whose tenancy backbone this builds on and partly revises.

> **Revision, 2026-09-25.** The first approved version kept Filament's company
> menu in the sidebar, put a placeholder "Firma wählen" menu in its place on
> `/admin`, added an "Alle Firmen" entry to it, and left archiving on the
> companies list. After using the built version, the owner asked for the
> switcher in the top bar, doing nothing but switching; the other entries in the
> sidebar; and archiving on the picker. This document is the revised design; §9
> lists what the revision replaced.

## 1. Purpose

`/admin` becomes the place where the user sees every company they belong to and
chooses one. Until now it was not a page at all: Filament redirected it into the
user's default company, or — with no company — forced the user into company
registration.

**Success is that `/admin` looks like the rest of the panel — same top bar —
with the company tiles where the dashboard's content would be, and that moving
between companies happens in one place: a switcher in the top bar.**

## 2. What the user sees

### 2.1 The top bar, on every panel page

> **Revised 2026-09-26** by `2026-09-26-topbar-trail-design.md`: the dropdown
> now carries a "Firma wechseln" label, initials avatars and, under a divider,
> "Firmen verwalten" and "Neue Firma"; the switcher shows inside an archived
> company opened by its URL; and — after the owner's second concept — it
> takes the brand logo's place, which is hidden, so "Firmen verwalten" is the
> way back to `/admin` rather than the logo (below and §2.2). The bullets below
> are otherwise unchanged.

- Brand, then the **company switcher**, then the user menu on the right.
- **The switcher** shows the current company's avatar and name ("B BALT ▾"); on
  `/admin`, where none is current, a neutral icon and "Firma wählen ▾".
- **Its dropdown lists the user's active companies, and nothing else.** Choosing
  one opens `/admin/{slug}`. No "Neue Firma", no settings, no list of companies
  — the switcher only switches.
- **With no active company at all**, the switcher is not shown: there is nothing
  to switch to.
- **The brand logo leads to `/admin`**, never into a default company. *(Since
  2026-09-26 the logo is hidden and "Firmen verwalten" in the switcher leads
  there, from the same home URL.)*
- **No global search box, for now.** Filament's search searches resources, and
  the companies list was the only one; with it gone (§2.5) Filament shows no
  box. It returns by itself with the first searchable resource (customers).

### 2.2 Inside a company, `/admin/{company}`

> **Corrected 2026-09-26.** The settings page is labelled **Einstellungen**
> throughout — sidebar entry, page heading and top-bar trail — as the Penpot
> mockups name it. Read every „Firmendaten" below as that label. The word is not
> retired: the dashboard's first step still reads „Firmendaten
> vervollständigen", because there it names the *data* rather than the page.

- **Sidebar:** two entries — **Dashboard** and **Firmendaten** (the company's
  settings, `/admin/{company}/settings`, highlighted while open). Filament's
  company menu at the top of the sidebar is gone.
- **Dashboard:** Filament's two default widgets are removed. It shows an empty
  state — **"Noch keine Inhalte."** with a **"Firmendaten vervollständigen →"**
  link to the settings — until invoicing gives it real content. An empty screen
  says what to do next (`.ai/guidelines/ui/core.blade.php`).
- **Back to all companies:** the brand logo. *(Since 2026-09-26: "Firmen
  verwalten" in the switcher; the logo is hidden.)*

### 2.3 `/admin` — "Firma wählen"

- **No sidebar.** The top bar is identical to the company pages; the content
  uses the full width. Every sidebar entry belongs to a company, and none is
  chosen here.
- **Content:** heading "Firma wählen", a **"Neue Firma"** header action, and a
  grid of **tiles**.
- **Tile:** a Filament section card. The **company name is a link** to
  `/admin/{slug}`; beneath it the legal form as its German label (`GmbH`,
  `Einzelunternehmen`); at the right a **⋮** menu (Filament's `ActionGroup`, per
  the row-action convention) with **"Deaktivieren"**, which asks for
  confirmation. The name, not the whole card, is the link: a button inside a
  link is invalid HTML.
- **Which companies:** exactly `User::getTenants()` — the user's non-archived
  companies, by name. The switcher lists the same, so the two cannot disagree.
- **Archived companies:** below the tiles, a collapsed section **"Deaktiviert
  (n)"** listing each of the user's archived companies with **"Wieder
  aktivieren"**. Shown only when there is at least one.
- **Browser title:** "Firma wählen". Shown whatever the number of companies, so
  `/admin` always means the same thing.

### 2.4 `/admin` with no active company

In place of the tiles, an empty state — **"Noch keine Firma angelegt."** — with
a "Neue Firma" action; the header action is then not shown, since the page would
show it twice. The "Deaktiviert" section still appears if archived companies
exist.

**Nothing redirects to registration.** A user creates a company by choosing to —
a brand-new user too: the first login lands on `/admin`, not `/admin/new`.

### 2.5 Archiving lives on the picker

- **The companies list is removed** (`CompanyResource`, `/admin/{company}/companies`):
  its only jobs — seeing all companies, archiving, restoring — are the picker's
  now. Editing stays on Firmendaten, creating on registration.
- **Archiving the last active company is allowed.** companies-and-tenancy §5.4
  refused it because it was a dead end: Filament forced registration and no
  screen outside a company existed. `/admin` now always renders, and restoring
  is on it, so the guard has no reason left. `canBeArchived()`, its row lock and
  `CannotArchiveLastCompany` go with it. Archiving an already archived company
  leaves its archive date alone.
- **Both actions act only on the user's own companies.** They find the company
  through the user's join table; a request naming another user's company does
  nothing.

### 2.6 Getting there

- **Login lands on `/admin`**, including an already signed-in visit to the login
  page. A link the user was on their way to when asked to log in still wins
  (`redirect()->intended()`).
- **After registering** a company the user lands on its settings page, as before
  (companies-and-tenancy §6).

## 3. Components

Each unit has one job.

1. **`SelectCompany` — the `/admin` page.** A full-layout Filament `Page`, not
   discovered (discovery would register it under every company). Tiles, empty
   state, archived section, "Neue Firma" header action, and the archive and
   restore actions. Never redirects.
2. **Serving it on `/admin`.** Filament registers `GET /admin`
   (`RedirectToTenantController`) after any application route, and the later
   registration wins, so `AppServiceProvider` binds that controller to
   `SelectCompany`; a Livewire page is invokable. Route name, path and middleware
   are unchanged.
3. **Panel configuration, `AdminPanelProvider`:**
   - `->tenantMenu(false)` — Filament's company menu is off everywhere, and its
     menu items with it.
   - `->navigation(fn () => Filament::getTenant() !== null)` — the sidebar exists
     only inside a company.
   - a **Firmendaten** navigation item pointing at the settings page.
   - a `TOPBAR_LOGO_AFTER` render hook drawing the switcher.
   - `->homeUrl()` → `/admin`, and a `Login` subclass for the signed-in visit.
4. **`CompanySwitcher` — a Blade view** built from Filament's dropdown components
   and its tenant-menu classes, so Filament's shipped CSS styles it.
5. **`Dashboard`** — Filament's dashboard without widgets, rendering the empty
   state of §2.2.
6. **`Company::archive()` / `unarchive()`** — unguarded state changes; the
   picker's actions call them after resolving the company through the user.

No Filament view is published or overridden. There is no frontend build
(`CLAUDE.md`), so only classes in Filament's pre-built CSS exist; where none
fits, an inline style.

## 4. Spike first

Three questions are settled by a throwaway spike, checked by screenshot, before
anything is built on them. If one cannot be done cleanly, work stops and the
question goes back to the owner.

1. **Placement:** does the switcher sit cleanly after the brand, light and dark?
2. **Phones:** is it reachable at phone width, where Filament hides the top
   bar's brand area? If not, where can it go on a phone?
3. **Actions on repeated sections:** do ⋮ actions on one section per company
   resolve the right company?

### 4.1 What the spike found (revision)

- **Placement:** after the brand (`TOPBAR_LOGO_AFTER`) the switcher sits as
  designed on desktop.
- **Phones:** Filament hides the brand area and any `.fi-topbar .fi-tenant-menu`
  below 64rem, and its shipped CSS has no global responsive-hide class. The
  phone copy therefore sits before the user menu (`GLOBAL_SEARCH_BEFORE`),
  without the `fi-tenant-menu` class, hidden from 64rem by one CSS rule the
  panel injects (`STYLES_AFTER`). It shows the avatar only; a name there wrapped
  onto three lines.
- **Actions on repeated sections:** a ⋮ action on each company's section
  resolves that company; a call naming a company not on the page throws
  Filament's `ActionNotResolvableException` and changes nothing.
- **Found while checking it in the browser, and in review:** after archiving or
  restoring, Filament rendered the content schema and header actions it had
  cached before the action ran, and the switcher — a separate top-bar
  component — did not re-render. The page now reloads `/admin` after either
  action, which rebuilds all of them.
- **Switcher with no active company:** hidden even inside an archived company
  opened by its URL, since its dropdown would be empty (§2.1).

### 4.2 Findings of the first round (sidebar placement), kept for reference

The first version's spike found: a sidebar render hook at `SIDEBAR_START`
matched Filament's menu box exactly on desktop; an empty `NavigationBuilder`
kept an empty sidebar; and global search on `/admin` threw
`UrlGenerationException` because company results linked to the companies list.
The revision makes all three moot: the switcher leaves the sidebar, the sidebar
is hidden on `/admin`, and the companies list — the only search source — is
removed.

## 5. Testing

Pest against PostgreSQL. Each test is chosen for what would make it fail.

- **Switcher:** in the top bar on `/admin` and on `/admin/{company}`, listing
  exactly the user's active companies (not archived ones, not other users');
  absent for a user with no active company; Filament's company menu
  (`fi-tenant-menu` in the sidebar) absent everywhere.
- **Sidebar:** none at `/admin`; inside a company, Dashboard and Firmendaten,
  Firmendaten linking to that company's settings.
- **Picker:** tiles in name order with German legal-form labels and slug links;
  escaped names; empty state without forced registration; "Neue Firma" to
  `/admin/new`.
- **Archiving on the picker:** "Deaktivieren" archives; "Wieder aktivieren"
  restores; the "Deaktiviert" section appears only when needed; archiving the
  last active company succeeds; **a forged action call naming another user's
  company changes nothing.**
- **Removed:** `/admin/{company}/companies` is 404; no search box is rendered.
- **Kept:** login and intended-URL landing, the signed-in login visit, the brand
  link, the dashboard empty state.

**Test data.** Tests never read the development database; every company a test
needs comes from `Company::factory()` in `invoice_test`.

**Screenshots.** Desktop and phone width, light and dark: `/admin` with several
companies (one archived, one with a long single-word name), `/admin` with none,
and `/admin/{company}`. Throwaway users `picker-two@example.test` and
`picker-none@example.test`, created before and deleted after; the owner's
account and companies are only viewed.

## 6. Documents this change falsifies

- **`README.md`** — the picker entry (switcher in the top bar, archiving on the
  picker), the "Companies are deactivated" entry, and the company-menu wording.
- **`CLAUDE.md`** — check for the companies list or the company menu.
- **`2026-09-23-invoice-system-design.md` §3.2** — check its 2026-09-25 note
  still reads true.
- **`2026-09-24-companies-and-tenancy-design.md`** — not edited; dated (its §10).
- **Outline, "Running It Locally"** — its "Once you're in" paragraph describes
  the sidebar company menu and "Alle Firmen"; "Domain Model" — check.

## 7. Out of scope

- **Search** — returns with the first searchable resource.
- **Dashboard content** — arrives with invoicing.
- **Roles and multi-user** — unchanged; everything lists the acting user's
  join-table companies and nothing more.

## 8. Decisions

| Decision | Choice |
|---|---|
| Where the tiles live | On `/admin`, outside any company |
| Company switcher | Top bar, after the brand; lists active companies only |
| `/admin` layout | Same top bar; no sidebar |
| Sidebar in a company | Dashboard, Firmendaten |
| Back to all companies | The brand logo |
| Tile | Name as link, legal form, ⋮ with "Deaktivieren" |
| Archived companies | Collapsed "Deaktiviert (n)" section on `/admin` with restore |
| Companies list | Removed |
| Global search | Absent until a searchable resource exists |
| Creating a company | "Neue Firma" on `/admin`; never forced |
| After login | `/admin`, unless an intended URL is pending |
| Company dashboard | Default widgets removed; empty state pointing to Firmendaten |
| Last-company archive guard | Removed |
| Mechanism | Unbound page + panel config + render hook; no forked Filament views |

## 9. What the revision replaced

The sidebar placeholder menu (`SIDEBAR_START` hook); the empty-navigation
builder; Filament's company menu inside companies and its "Alle Firmen",
"Firmen verwalten", "Firmendaten" and "Neue Firma" entries; the companies list
and its global-search URL override; and the §7 gap that restoring needed an
archived company's URL.
