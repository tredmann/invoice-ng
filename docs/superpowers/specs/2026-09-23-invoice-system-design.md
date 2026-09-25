# Invoice — Design

Date: 2026-09-23
Status: Approved (brainstorming complete, ready for implementation planning)

## 1. Purpose

A billing web application for one person who runs several of their own
companies and needs to issue German invoices that satisfy German
bookkeeping rules (GoBD) and the e-Rechnung obligation.

Today it has exactly one user. It is built so that "many users, each with
their own companies" remains possible without restructuring, but no
signup, roles, or per-seat billing are built now.

Success means: the owner can run the real invoicing of a real company in
this system — issue, send, get paid, correct mistakes, and hand a clean
period to a tax advisor — without falling back to the previous tool.

Stack: Laravel with Filament 5. Stack choice was made before this design
and is not revisited here.

## 2. Scope

### In

Outbound billing only.

- Companies and switching between them
- Company master data
- Customers
- Invoices: draft, issue, ZUGFeRD PDF, email
- Storno (full reversal) and Gutschrift (partial credit)
- Payment recording
- Recurring invoices
- Reminders (Mahnwesen)
- Period export for the tax advisor
- Dashboard reporting
- Audit timeline

### Out, deliberately

- Quotes / Angebote
- Time and expense capture
- Incoming supplier invoices and expense tracking
- A product/service catalog
- Public sector (B2G): no XRechnung, no Leitweg-ID, no Peppol
- EU cross-border and third-country invoicing
- Currencies other than EUR; invoice languages other than German
- Skonto
- Bank statement import or bank connections
- DATEV export
- Customer-facing portal

### Customers and documents

German B2B and B2C only. Invoices are EUR and German. The application
interface is translatable; the invoice document is not.

## 3. Domain model

### 3.1 Identifiers

**Every model uses a UUID as its primary key.** Not a bigint with a UUID
beside it — the UUID *is* the key, and foreign keys carry UUIDs.

The reason is that identifiers on this system leak. They appear in URLs the
user bookmarks and sends, and a sequential key tells anyone holding one how
many companies, customers or documents exist, and lets them walk to a
neighbour's. That is a disclosure problem in a system holding other
businesses' books.

**Version 7**, via Laravel's `HasUuids` — which on this framework version
generates v7 rather than v4. That matters: a v7 UUID leads with a timestamp,
so keys sort in creation order and new rows land at the end of the index
instead of scattering across it. The usual objection to UUID keys — random
insertion wrecking index locality — does not apply.

The cost that remains is width: sixteen bytes against eight, in the table and
in every index and foreign key referencing it. Not load-bearing at this
volume.

> An earlier draft of this section also listed "no natural insertion order" as
> a cost, and sent the reader to `created_at`. That was written before the
> version was pinned down and is simply wrong for v7. Corrected rather than
> left standing, since it would have argued someone out of ordering by key.

Note that this is **unrelated to document numbers**. Invoice numbers are
gapless, sequential and legally mandated (§5); they are not identifiers of
rows and are never used as keys.

### 3.2 Tenancy

A user is linked to companies through a join table, not by direct
ownership. Today that table holds one user and carries no roles. It is
the minimum structure that keeps multi-user possible later: adding
collaborators becomes a column, not a migration of everything.

A current company is selected on login and can be switched. All data the
user sees belongs to that company. There are no cross-company views.

> **Corrected 2026-09-25.** "Selected on login" is now literal: login lands on
> a company picker at `/admin`, in the full panel layout, with one tile per
> non-archived company the user belongs to, instead of redirecting straight into
> a default company. Nothing forces a user into company registration; with no
> company the picker says so and offers it. The picker shows which companies
> exist, not any of their data, so it is not a cross-company view. See
> `docs/superpowers/specs/2026-09-25-company-picker-design.md`.

**The current company is in the URL, not only in the session.** Every
company-scoped screen lives under the company's slug — `/{company}/invoices`,
`/{company}/customers`, `/{company}/settings` — and the slug is derived from
the company name, unique, and stable once issued.

> **Corrected 2026-09-24.** The companies-and-tenancy wave built this behind
> Filament's `/admin` prefix rather than at the root, so the real paths are
> `/admin/{company}/invoices`, `/admin/{company}/customers`,
> `/admin/{company}/settings`. The property this section argues for is that
> the company sits in the path rather than in session state, and the prefix
> does not weaken that — it just means the path has one more fixed segment.
> Keeping the prefix also leaves the root free for a real 404 instead of every
> unrecognised top-level segment being read as a company slug. See
> `docs/superpowers/specs/2026-09-24-companies-and-tenancy-design.md` §5.2.

This is what makes a link mean one thing. With the company held only in
session state, the same URL shows different companies to the same person
depending on what they last clicked, so a bookmarked or shared link is
ambiguous and a second browser tab silently fights the first. Putting the
company in the path also gives the tenancy boundary something to check
against rather than infer.

The slug is a routing convenience, not an identifier: the key is still the
UUID of §3.1.

The tenancy boundary is enforced globally at the data layer, not
remembered at each query site. Every tenant-owned record carries its
company and reads are automatically constrained to the current one. The
characteristic failure of a multi-company invoicing system is one
company's number sequence or customer leaking into another's; this is
prevented structurally.

### 3.3 Master data

**Per company, configured once:**

- Identity block for the document footer: full address, Steuernummer
  and/or USt-IdNr, Handelsregister and HRB number, Geschäftsführer
- Bank details: IBAN, BIC, bank name
- Logo
- Tax rates, one marked as default
- Kleinunternehmer flag (§19 UStG)
- Payment terms
- Number range configuration
- SMTP sending credentials
- Email templates, one per document type
- Optional BCC address for all outgoing mail

**Per company, maintained ongoing:**

- Customers. A customer is either a **company** (VAT-ID, contact person)
  or a **private person**. Both carry a customer number, billing address,
  billing email, and a default payment term. The type is visible on the
  invoice.

**Global, shared:**

- The unit list (Stück, Stunde, Tag, Pauschal, km, …). Each unit carries
  its UN/ECE code, required by the XML. These are a standard, not
  per-company data, and are not user-editable.

**No product or service catalog exists.** Line items are typed freely on
each invoice and reference nothing. If a catalog is ever added it will be
a convenience that writes values into line items, never something line
items depend on.

### 3.4 Archiving

Nothing referenced by an issued document is ever deleted. Customers, tax
rates and companies are deactivated: they disappear from pickers and
remain intact on every document that references them.

Drafts are the exception — they never received a number and can be
deleted outright.

### 3.5 Documents

One documents table holds all three types. Type-specific behaviour lives
in three distinct classes rather than in conditionals spread through the
codebase, so a fourth document type later means a new class, not edits to
switch statements.

Document fields: company, type, number, status, customer, issue date,
Leistungsdatum, due date, frozen block, stored totals, parent document
reference.

| Type | Due date | Accepts payment | Can be reminded | References |
|---|---|---|---|---|
| Invoice | yes | yes | yes | — |
| Storno | no | no | no | exactly one invoice |
| Gutschrift | no | no | no | exactly one invoice |

- **Invoice** — the normal document. Can be cancelled or credited.
- **Storno** — a full reversal. Mirrors its parent negatively. Cannot
  itself be cancelled or credited; nothing reverses a reversal.
- **Gutschrift** — a partial credit. Carries its own freely-typed lines
  rather than mirroring the parent. The parent stays valid and open for
  the remainder.

The rest of the system asks a document what it can do rather than
inspecting its type.

Status: `draft` → `issued` → `sent` → `paid`, plus `cancelled`. A
`partially_paid` status is reserved for when partial payments are enabled
(§10, Later); it is unreachable in v1, where a payment always settles the
full open amount.

### 3.6 Line items

Position, title, description, quantity, unit, unit price, tax rate, line
net. Plain values; they reference no master data.

### 3.7 Payments

Rows against a document: date, amount, note.

In v1 the interface offers "mark as paid", writing a single row for the
full open amount. Because it is a row rather than a flag, enabling
partial payments later is a user-interface change with no data migration.

### 3.8 Audit timeline

Append-only rows: subject, event, timestamp, actor, details. Nothing ever
updates or deletes an entry.

Entries are written by the same transactions that perform the work, so an
event cannot be recorded for something that did not happen, nor missed
for something that did.

Shown as a timeline on each document and queryable across documents.
Events include: created, issued, PDF generated, sent, resent, reminder
sent, payment recorded, cancelled, credited.

## 4. Freezing and immutability

A draft is freely editable and carries no number.

At the moment of issue, three things are frozen:

1. **The number** is drawn and permanently assigned.
2. **The block** — seller identity, buyer billing address, and payment
   terms as they read at that instant — is copied onto the document.
   Because there is no product catalog, line items are already the
   document's own data and need no snapshot; the frozen block is
   therefore small and covers only what came from master data.
3. **The PDF file** is generated once and stored. It is served from
   storage forever after and never regenerated. Changing a logo or
   template next year leaves last year's documents exactly as the
   customer received them.

**After issue, the document and its line items are immutable.** This is
enforced at the model layer — writes are rejected, not merely hidden in
the interface. Only status, payments and audit entries may still change.

## 5. Numbering

- One sequence per company, shared by all three document types. A Storno
  may be number 0002 between invoices 0001 and 0003.
- The pattern is configurable per company: prefix, year, padding, and
  whether the counter resets annually.
- The starting value is settable, so a company migrating in can continue
  from where its previous system stopped.
- **Gapless.** The number is drawn under a lock inside the issue
  transaction. If any later step fails, the transaction rolls back and
  the number is not consumed. Concurrent issues serialize; the second
  waits.

A Mahnung is **not** an invoice and does not consume an invoice number.
It has its own separate sequence. Placing reminders in the invoice
sequence would leave permanent holes in the bookkeeping record.

## 6. Tax and rounding

Tax rates belong to the company, one marked default. Each **line item**
carries its own rate, so a single invoice may mix 19% and 7%. The PDF and
the XML show a VAT summary grouped by rate.

A company flagged **Kleinunternehmer** issues at 0% with the required §19
note and no VAT block.

All money is stored in integer cents. Floats are never used.

Rounding follows EN16931 and is not negotiable:

1. Each line's net is quantity × unit price, rounded to the cent.
2. Lines are grouped by tax rate; each group's base is the sum of its
   line nets.
3. Each group's VAT is base × rate, rounded once.
4. Totals are the sums of the group figures.

Rounding happens per group — not per line, not at the end. This is what
makes the PDF agree to the cent with the recipient's accounting software.

Totals are stored on the document, not recomputed on display.

## 7. The e-Rechnung document

**ZUGFeRD / Factur-X only**: a PDF/A-3 file with EN16931-profile XML
embedded. One file that is both human-readable and machine-readable. No
bare XML output, no XRechnung.

The XML is validated against the EN16931 schema **inside the issue
transaction**. A document that would not pass the recipient's software
never becomes an issued invoice.

**Layout:** one house template for all companies in the first iteration,
with each company's logo and identity block substituted in.

The document carries: the company identity footer, customer billing
address, customer number, invoice number, issue date, Leistungsdatum
(one date or period per invoice — line items do not carry their own),
line items, VAT summary by rate, totals, payment terms and due date, bank
details.

Intro and closing texts are fixed in the PDF template and are not
editable per invoice from the interface. There are no reference fields
(no customer order number, project, or cost centre).

## 8. Core operations

### 8.1 Issue

One transaction, all or nothing:

1. Lock the company's number range; take the next number
2. Freeze the block (seller, buyer, payment terms)
3. Compute and store totals
4. Render the ZUGFeRD PDF; validate the XML; write the file
5. Set status to issued; write the audit entry

Any failure rolls the whole thing back: no number consumed, document
still a draft, error shown.

Before the transaction opens, a **readiness check** runs on the company:
number range, tax number, bank details, logo. Missing prerequisites are
reported as a list, not raised as an exception halfway through issuing.

### 8.2 Correct an invoice

One action, one transaction, three effects:

1. A `Storno` is created **and issued immediately**. It is a mechanical
   mirror with nothing to edit; leaving it as a draft would only invite
   forgetting it.
2. The original is marked `cancelled`.
3. A **new draft** opens, pre-filled with the original's line items.

Three documents, three numbers, balanced books, and the wrong invoice
visibly dead rather than deleted.

Cancelling an invoice that already has payments is **allowed but warns**.
The money genuinely arrived; the payment stays recorded against the
cancelled invoice.

### 8.3 Credit part of an invoice

A separate and deliberately slower action: it opens a **draft
Gutschrift** referencing the invoice. The user types the credited lines
and issues it. The invoice remains valid and open for the remainder.

### 8.4 Open amount

    open = total − payments − credits

Status follows from this figure rather than being set by hand.

### 8.5 Send

Takes the **stored PDF file**, never a fresh render. Fills the company's
email template for that document type. Sends through that company's SMTP,
with the optional BCC. Attaches the ZUGFeRD PDF alone — no separate XML
file.

Issuing and sending are **separate transactions**. An invoice that is
issued but not yet sent is a normal, recoverable state. An invoice that
was sent but never properly issued is impossible.

A failed send leaves the document issued and is retryable. Resending is
allowed and logged each time. Downloading the PDF is always available, so
a customer without an email address is not a dead end.

In v1 the user presses send, so failures surface immediately on screen.
This is what makes fire-and-forget delivery acceptable, and is precisely
what stops being true in v2 (see §12).

### 8.6 Duplicate

"Duplicate this invoice" copies an existing document's line items into a
new draft for the same customer. With no catalog, this is the main
time-saver for repetitive non-recurring work.

## 9. Reporting

A dashboard, scoped to the current company:

- Revenue this month and year to date
- Open receivables
- Overdue invoices needing attention
- Worklists: reminders awaiting release, drafts awaiting issue

Reports are looked at, not exported, in the first iterations. VAT per
period is available as a figure for the owner's USt-Voranmeldung.

## 10. Roll-out

### v1

The implementation plan that follows this spec covers v1 only.

Companies and switching; company master data; customers; invoice draft →
issue → frozen ZUGFeRD PDF → email; Storno and Gutschrift with the
one-click correction flow; duplicate; mark as paid and the open-items
list; audit timeline; dashboard.

### v2

Recurring invoices; reminder worklist and Mahnung document; period export
(PDF zip + CSV) and VAT per period.

### Later

Partial payments; additional dunning stages; Mahngebühren and
Verzugszinsen; report export; DATEV.

There is no migration and no cutover deadline. No existing customers or
open invoices are imported; the previous system is left to die out on its
own.

## 11. v2 in outline

Described here only to the extent that v1 must not preclude it.

### Recurring invoices

A contract holds: customer, template line items, interval, start date,
optional end date, next-run date, status (active / paused / ended).
Controls: pause, end, cancel, change price.

A daily job finds due contracts and runs **the same issue operation** a
manual invoice runs — same numbering lock, same freezing, same PDF, same
audit. There is no second path to creating an invoice. This is the reason
§8.1 defines issuing as one named operation rather than something the
edit screen performs.

Line items are fixed per period. Leistungszeitraum is computed from the
billed period and printed on the invoice. Invoices are issued **and sent
automatically**.

**Idempotency** is required: a job that runs twice or retries after a
crash must not issue two invoices for the same period. The guard is a
uniqueness constraint on (contract, period), enforced by the database
rather than by a code check.

**Scheduled future price changes are explicitly out of scope.** Editing
template lines naturally affects only future runs, since issued invoices
are frozen. Dating those changes in advance would require versioned line
items; it can be added later if the absence proves annoying.

### Reminders

Dunning levels are configuration rows, with one seeded
(Zahlungserinnerung). A daily scan builds a worklist of overdue invoices.
The user **releases each one by hand** — an automatic reminder reaching a
customer who paid yesterday is a different class of error from an
automatic invoice.

Releasing renders a **Mahnung document** (stored PDF, own number
sequence, per §5) and writes an audit entry. The original amount is
unchanged; no fees or interest in this iteration.

### Period export

Because PDFs are frozen files, an export is assembly rather than
rendering: collect the period's stored files, write a CSV alongside
(date, number, customer, net, VAT, gross), zip it. VAT per period is a
query over stored totals grouped by rate.

Both are cheap *because* of §4. Were PDFs regenerated on demand, a 2026
export run in 2028 would produce documents that differ from what
customers received.

## 12. Known risks

1. **Gapless numbering makes issuing a heavy operation.** The lock and
   full rollback are correct for German bookkeeping, but "issue" is not a
   cheap write, and it serializes. Accepted deliberately.

2. **Automatic sending plus fire-and-forget delivery is safe only while
   sending is manual.** Once v2 recurring invoices send themselves
   unattended and nobody watches for bounces, an invoice can silently
   never arrive. Revisit before v2 ships — at minimum, surface send
   failures in a worklist.

## 13. Error handling

| Situation | Behaviour |
|---|---|
| Concurrent issue (user + job) | Number-range lock serializes; distinct consecutive numbers, no gap |
| PDF or XML generation fails | Whole transaction rolls back; number not consumed; document still a draft |
| XML fails EN16931 validation | Issue fails inside the transaction; no invalid invoice is ever issued |
| Email send fails | Document stays issued; attempt logged; retryable |
| Company not ready to invoice | Readiness check before the transaction; missing items listed |
| Customer has no billing email | Send unavailable with reason; download still works |
| SMTP not configured | Send unavailable with reason |
| Cancelling an invoice with payments | Allowed, with a warning; payment stays recorded |

## 14. Testing strategy

Coverage is concentrated where being wrong is expensive and invisible,
not spread evenly.

**The issue operation** — the hardest tests, and the most valuable:

- Concurrent issues produce distinct, consecutive numbers
- A forced PDF failure consumes no number and leaves a draft
- Writing to an issued document raises rather than saves

**Rounding** — a table of cases with expected values derived from the
EN16931 rules, not from what the code happens to produce:

- 19% and 7% mixed on one invoice
- Fractional quantities
- 0% under Kleinunternehmer
- The case where per-line and per-group rounding differ by a cent

**ZUGFeRD output** — validated against the **official validator**, not
against our own assertions. Our opinion of the XML is worth nothing; the
recipient's software is the judge.

**Tenant isolation** — a standing test that queries under company B never
return company A's rows, including the number sequence.

**The correction flow** — Storno, cancelled original and pre-filled draft
all appear or none do; open amount accounts for credits as well as
payments.

Everything else (CRUD on customers, form validation) receives ordinary
coverage.

## 15. Decisions made during design

| Decision | Choice |
|---|---|
| Primary keys | UUID on every model; the UUID is the key, not a column beside a bigint |
| Company in the URL | Every company-scoped screen lives under the company slug, e.g. `/admin/{company}/invoices` — corrected 2026-09-24, see §3.2 |
| Panel path | Keeps Filament's `/admin` prefix; company-scoped screens are `/admin/{company}/…` |
| Identifier language | English, except legal designations that print verbatim |
| Table row actions | A single vertical-ellipsis button opening a dropdown; never a row of buttons |
| Audience | Single user now; multi-user kept possible, not built |
| Document model | One table, three classes with their own rules |
| Storno vs Gutschrift | Storno = full reversal; Gutschrift = partial credit |
| Correction flow | One click: Storno issued + original cancelled + new draft |
| Storno issuance | Issued immediately, not left as a draft |
| Numbering | One sequence per company for all types; gapless; configurable pattern; settable start |
| Mahnung numbering | Separate sequence; does not consume invoice numbers |
| PDF | Frozen file at issue; never regenerated |
| Snapshot | Seller, buyer address and payment terms only — line items are already own data |
| Product catalog | None. Contradicts the original Outline note; superseded here |
| Units | Global fixed list with UN/ECE codes |
| e-Rechnung | ZUGFeRD only; XML validated inside the issue transaction |
| Layout | One template, company logo and identity substituted |
| Invoice free text | Fixed in the template; not editable per invoice |
| Leistungsdatum | Per invoice, not per line |
| Reference fields | None |
| Payments | Rows, not a flag; v1 writes one full-amount row |
| Skonto | Out |
| Delivery | Per-company SMTP; templates per document type; PDF alone; fire and forget; optional BCC |
| Reminders | One stage now, stages as data; worklist with manual release; document + audit entry |
| Export | PDF zip + CSV; no DATEV |
| Reporting | Current company only; no cross-company roll-up |
| Deletion | Archive only; drafts deletable |
| Scheduled price changes | Dropped |
| Migration | None |
