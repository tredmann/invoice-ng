# Top-Bar Trail — Design

Date: 2026-09-26
Status: Approved in conversation; written spec awaiting review
Companion to: `2026-09-23-invoice-system-design.md`, which remains the authority
on the system as a whole, and `2026-09-25-company-picker-design.md`, whose
top-bar switcher this revises (§7).

## 1. Purpose

The owner drew the top bar they want (`breadcrumb.pdf`, outside the repo) and,
after using the first build, redrew it (`breadcrumb2.pdf`): the company
switcher takes the logo's place at the top left, and the page's breadcrumb
trail — `Kunden › Bauer & Kollegen GmbH` — moves from above the heading into
the top bar, over the content column.

> **Revised 2026-09-26 (breadcrumb2).** The first build kept the logo and put
> the switcher at the start of the trail, as its first crumb. The owner's
> redesign removes the logo, puts the switcher where it was, and leaves the
> trail to start on its own at the content column. §2.1–§2.3 and §3.1 describe
> the redesign.

**Success is that, inside a company, the top bar says in which company the user
is — above the sidebar — and where, starting exactly where the page content
below it starts, and that the switcher's dropdown looks like the concept.**

Only the top bar is in scope. The rest of the concept — the brand at the top of
a full-height sidebar, dashboard content, invoices, the user avatar — is not
(§8).

## 2. What the user sees

### 2.1 Top bar, desktop (from 64rem), inside a company

- **No logo.** The switcher takes its place at the top left, as wide as the
  sidebar below it: avatar, name, and the chevron at the far end. It lines up
  with the sidebar's entries. The way back to the picker is "Firmen
  verwalten" in its dropdown (§2.5).
- **The trail** sits over the content column, with the same maximum width,
  centring and side padding as the page content (`fi-main`). Its first crumb
  starts exactly where the page heading starts — at 1280 px, and at 1920 px
  where the content is centred.
- **The user menu** stays at the far right.
- **Crumbs:** every crumb but the last is a gray link; the last is the current
  page, darker, and not a link. Gray chevrons separate crumbs — between them
  only, none before the first.
- **The page draws no breadcrumbs** above its heading any more.

### 2.2 The trail

The section, then — where there is one — the record, then the page if it is
not the record itself. No "Liste", no "Anzeigen". The company is not a crumb:
the switcher names it.

| Page | Trail |
|---|---|
| Dashboard | `Dashboard` |
| Einstellungen | `Einstellungen` |
| Customer list | `Kunden` |
| New customer | `Kunden › Neuer Kunde` |
| View a customer | `Kunden › Bauer & Kollegen GmbH` |
| Edit a customer | `Kunden › Bauer & Kollegen GmbH › Bearbeiten` |

In the edit trail, `Kunden` links to the list and the name to the view page.
Resources added later — invoices first — follow the same rule without further
design: `Rechnungen › RE-2026-0042`, `Rechnungen › Neue Rechnung`.

### 2.3 Top bar on `/admin`

`Firma wählen ▾` in the switcher's place at the top left, with no trail. The
switcher is hidden when the user has no active company.

### 2.4 Phones (below 64rem)

Unchanged: the switcher shows as its avatar alone, before the user menu. No
trail — beside the user menu there is no room for one.

### 2.5 The dropdown

- A small gray label, **"Firma wechseln"**.
- The user's **active companies**, by name, each with an **initials avatar**: a
  gray rounded square. The **current company** has an amber-tinted avatar,
  amber text and a **✓** at the right.
- A divider, then **"Firmen verwalten"** (building icon, → `/admin`) and
  **"Neue Firma"** (plus icon, → `/admin/new`).
- **On `/admin`, "Firmen verwalten" is left out**: it would link to the page
  the user is on.
- **Inside an archived company opened by its URL**, the switcher is shown — its
  dropdown always has entries now. The trigger names the archived company; the
  list, being active companies only, carries no ✓.

### 2.6 Initials

The first letter of each of the name's first two words, upper-cased:
"Kranz Ingenieurbüro GmbH" → "KI", "Übersee Handel" → "ÜH". A single word gives
one letter: "Balt" → "B". Letters are taken as characters, not bytes, so an
umlaut survives — also one pasted in decomposed form ("U" plus a combining
mark), which is composed first. "ß" stays one letter rather than "SS".

Drawn locally. Filament's default avatar is an image from ui-avatars.com — an
external request per company, and it cannot produce the concept's look.

## 3. Components

No Filament view is published or overridden, as in the picker spec (§3 there).
Each unit has one job.

1. **`Breadcrumbs` — the trail builder.** Given a page, returns
   `[url => label, …, label]` by the rule of §2.2. Works from Filament's own
   `getBreadcrumbs()`: drops the "Liste" and "Anzeigen" suffixes, and puts the
   page title ("Neuer Kunde") in place of "Erstellen". The only place the rule
   lives.
2. **`Topbar` — a subclass of Filament's top-bar component**, registered with
   `->topbarLivewireComponent()`. Holds the trail in a public `$trail`
   property:
   - On a full page load the page renders before the layout mounts the top bar.
     A listener on Livewire's `render` event records the page in a
     request-scoped holder (`RenderedPage`); the top bar builds the trail from
     it in `mount()` (spike findings: `Livewire::current()` is the top bar
     itself by then).
   - As a Livewire property, the trail survives the top bar's own re-renders:
     Filament's `Topbar` re-renders on a `refresh-topbar` event, which nothing
     in Filament or this application dispatches today, but anything may.
   - No page today changes its trail without navigating: saving a customer on
     its edit page redirects to the view page, which renders a fresh trail. A
     page that sends its trail over Livewire is left until one needs it —
     sending on every re-render would add a request to every keystroke of a
     live form.
   - **Company data** is the exception: renaming the company changes the
     switcher's name, and the settings page does not navigate on save. It
     dispatches `refresh-topbar` after saving, and the top bar re-renders with
     the new name while keeping its trail.
3. **The top-bar view** (`company-switcher` today, renamed for what it now
   holds): switcher and trail in one Blade view, drawn by the existing
   `TOPBAR_LOGO_AFTER` render hook. The phone copy at `GLOBAL_SEARCH_BEFORE`
   stays, avatar only, without the trail.
4. **`Company` initials** — a method on the model, used by the avatar in both
   the trigger and the list.
5. **Panel configuration, `AdminPanelProvider`:** `->breadcrumbs(false)`,
   `->topbarLivewireComponent(Topbar::class)`, and a `topbar-styles` view at
   `STYLES_AFTER` carrying the phone rule, the logo hiding, the switcher's
   width and the alignment of §3.1.

### 3.1 Alignment

**The switcher**, from 64rem, on every page: Filament's brand link in the top
bar is hidden (Filament has no setting that removes it), and the switcher is
as wide as the sidebar less the top bar's inline padding and a gap
(`calc(var(--sidebar-width) - 2rem)`), its trigger filling that width so the
chevron sits at the far end. Its left edge meets the sidebar entries' (16 px).

**The trail**, from 64rem and only with a sidebar (`.fi-body-has-navigation`):

- The trail column is laid **over** the top bar rather than placed in its flow:
  positioned absolutely from the sidebar's edge (`--sidebar-width`) to the
  right edge — the width the page content spans. In the flow, the user menu
  would narrow the column and shift its centring.
- Within it, a box with `fi-main`'s maximum width (`80rem`), `margin-inline:
  auto` and its desktop inline padding (`2rem`; `5rem` at the end, to keep a
  long trail clear of the user menu).
- The column ignores the pointer except over its own content, and the user
  menu is lifted above it (`z-index: 1`), so nothing underneath stops
  working.

Measured after building: the first crumb and the page heading share their left
edge at 1280 px (352 px) and 1920 px (512 px).

There is no frontend build (`CLAUDE.md`); these are plain CSS rules in the
injected `<style>`, and inline styles where a single element needs one.

## 4. Spike first

Throwaway, checked by screenshot at 1280, 1920 and 390 px, light and dark. If
one question cannot be answered cleanly, work stops and it goes back to the
owner.

1. **Handover:** does the trail reach the top bar on a full page load, and
   survive `refresh-topbar` with the company still set?
2. **Render hook:** can the view drawn at `TOPBAR_LOGO_AFTER` read the top
   bar's `$trail`? If not, which placement can, still without forking a view?
3. **Alignment:** do the switcher and the page heading share a left edge at
   1280 and at 1920 px, with the sidebar present?

## 5. Testing

Pest against PostgreSQL. Each test is chosen for what would make it fail.

- **Trail per page:** Dashboard, Einstellungen, customer list, new, view and edit
  each render exactly the crumbs of §2.2; earlier crumbs link to the right
  URLs, the last is not a link. *Fails if* Filament's "Liste", "Anzeigen" or
  "Erstellen" returns, or the trail goes missing.
- **Not above the heading:** no `fi-breadcrumbs` in the page header.
- **Escaping:** a customer named `Bauer & <Söhne> GmbH` appears in the trail as
  text — neither as markup nor as `&amp;amp;`.
- **Top-bar re-render:** a Livewire test of `Topbar` — the trail is still
  rendered after `refresh-topbar`; saving the company data dispatches
  `refresh-topbar`.
- **Dropdown:** "Firma wechseln"; exactly the user's active companies; the
  current one marked; "Firmen verwalten" → `/admin` and "Neue Firma" →
  `/admin/new`; "Firmen verwalten" absent on `/admin`; the switcher present
  inside an archived company, with no company marked. The existing test that
  "Neue Firma" is *absent* from the switcher is inverted.
- **Initials:** the cases of §2.6. *Fails with* byte-wise `substr`, which
  splits "Ü". And `ui-avatars.com` appears nowhere in the top bar's HTML.
- **Alignment** is CSS, which no assertion here can prove; the screenshots of
  §4 settle it, repeated once the work is done, with the dropdown open.

**Test data.** Tests never read the development database; every company and
customer comes from factories in `invoice_test`.

## 6. Documents this change falsifies

- **`2026-09-25-company-picker-design.md` §2.1** — a revision note: the dropdown
  now carries "Firmen verwalten" and "Neue Firma", and the switcher shows inside
  an archived company; see this spec. The rest of that spec stands.
- **`README.md`** — the switcher entry: its dropdown and the trail beside it.
- **`CLAUDE.md`**, **`.ai/guidelines/`** (and so `AGENTS.md`), and **system
  design §3.2** — check for claims about the switcher or breadcrumbs.
- **Outline** — "Running It Locally" and any page describing the switcher;
  search, then patch.

## 7. What this revises

Company-picker spec §2.1 said the dropdown lists the active companies "and
nothing else … the switcher only switches", and that the switcher is hidden
with no active company even inside an archived one. The owner's concept puts
"Firmen verwalten" and "Neue Firma" back under a divider, and the owner chose
it over a companies-only dropdown. With those entries the dropdown is never
empty, so the archived-company exception has no reason left; only `/admin`
with no active company still hides the switcher.

## 8. Out of scope

- **A full-height sidebar**, with the top bar spanning only the content, as
  the first concept drew it — the top bar still spans the page; only the logo
  in it gave way to the switcher.
- **Dashboard content, invoices** and the other screens of the concept.
- **The user menu's avatar.**
- **A trail on phones.**

## 9. Decisions

| Decision | Choice |
|---|---|
| Scope | Top-bar content only |
| Logo | Hidden; the switcher takes its place, sidebar-wide (breadcrumb2) |
| Alignment | Trail column mirrors `fi-main`, from 64rem with a sidebar |
| `/admin` | Switcher in the logo's place, no trail |
| Breadcrumbs above the heading | Removed (`->breadcrumbs(false)`) |
| Trail rule | Section › record › page; no "Liste"/"Anzeigen"; the company is not a crumb |
| Last crumb | Current page, not a link |
| Dropdown | "Firma wechseln", companies with ✓, divider, "Firmen verwalten", "Neue Firma" |
| "Firmen verwalten" on `/admin` | Left out |
| Archived company by URL | Switcher shown, no company marked |
| Avatars | Local initials; no ui-avatars.com |
| Phones | Unchanged; no trail |
| Mechanism | `Topbar` subclass + trail builder + render hook + injected CSS; no forked views |
