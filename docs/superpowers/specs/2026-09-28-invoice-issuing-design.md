# Rechnung ausstellen — Design

Date: 2026-09-28
Status: Built
Companion to: `2026-09-23-invoice-system-design.md`, which remains the authority
on the system as a whole. This document covers one wave of it — §8.1, and
everything §4 freezes.

Follows `2026-09-27-invoice-drafts-design.md`, which built the Beleg and stopped
deliberately short of issuing it.

## 1. Purpose

**ausstellen**: the one transaction that turns an **Entwurf** into a gültiger
**Beleg**. It draws the **Belegnummer**, writes the **Festschreibung**, stores
the totals, renders the ZUGFeRD PDF and makes all of it unveränderlich. All or
nothing — a failure anywhere consumes no number.

Success means four things. The owner can press one button and get a Rechnung
their customer's accounting software accepts. A company that is not ready is
told what is missing rather than shown an error. Nothing that has been issued
can be changed, and the file the customer received is the file that is served
forever after. And the number sequence has no gaps in it, under concurrency.

This is the wave that spends everything the master-data wave built:
`DrawNextNumber`, `CheckReadiness`, `CalculateTotals` and `MoneyCast` each had
exactly one caller — a test — until now.

## 2. Carried in

Settled elsewhere and applied here, not revisited:

- The issue transaction and its order (system design §8.1)
- Gapless numbering under a lock, held for the length of the render (§5, §12)
- Per-group VAT rounding (§6), already `App\Actions\CalculateTotals`
- ZUGFeRD / Factur-X only: PDF/A-3 with EN16931 XML embedded; no bare XML, no
  XRechnung, no Peppol (§7)
- Only what §14 UStG and §35a GmbHG require may refuse; bank details and logo
  warn (§8.1 as corrected 2026-09-26)
- Blade → WeasyPrint → `horstoeko/zugferd` → disk, upload before commit
  (tech stack §7.1, §7.2)
- `CONTEXT.md` names the frozen block **Festschreibung** → `frozen_block`, and
  the operation **ausstellen** → `issue`
- Money is integer cents; floats never touch it

## 3. Decisions taken for this wave

| Decision | Choice |
|---|---|
| `documents.number` | Becomes a **string** holding the Belegnummer as it prints |
| Country code | Frozen as `DE`; no country column |
| Runtime XML validation | XSD through libxml, inside the transaction |
| Business-rule validation | The official EN16931 Schematron, over golden fixtures, in the suite — through **SaxonC-HE**, not Java |
| Stored totals | The three sums; the per-rate groups stay derived |
| Audit events | One `Issued` entry, not two |
| Tax identifiers | Validated where they are typed, not at issue; the forms' accepted set is held to EN16931's by test — added 2026-09-28 |
| Scope | §8.1 only. No Storno, no Zahlungen, no Versenden |

### 3.1 The Belegnummer is a string

`DrawNextNumber` returns `RE-2026-0042`, and `documents.number` was an integer.
One of them had to move, and it was the column.

The Belegnummer is the formatted value: that is what §14 Abs. 4 Nr. 2 UStG calls
the number, what prints, what the customer quotes back, and what must never
change. The integer behind it is a property of the **Nummernkreis**, and
`number_ranges` is the only place that has to count. Storing both would be two
columns for one concept, both frozen, and a standing invitation to disagree.

No issued Beleg existed anywhere, so the migration drops the column and re-adds
it rather than converting in place.

**Consequence accepted:** an audit query looking for holes in the sequence
cannot walk `documents` directly; it has to compare `drawn_count` against a
count of issued Belege, or parse. Nothing needs that yet.

### 3.2 The country is frozen as DE, and is not a field

EN16931 makes a country code mandatory on both postal addresses (BR-09, BR-11),
and neither `companies` nor `customers` has one.

It is hardcoded rather than asked for, because **nothing in this system can tax
a supply outside Germany**: there is no reverse charge, no §13b, no intra-EU
exemption. A country picker would let an owner address an invoice to Vienna that
this application would then charge 19 % German VAT — a wrong invoice, produced
confidently. Not offering the choice is the more honest of the two.

The day one of those rules exists, `FrozenBlock::COUNTRY` becomes a column.

### 3.3 XSD at runtime, Schematron in the suite

Tech stack §10.2 asks for two levels, and names the KoSIT validator — a Java
tool — for the second. There is no CI in this repository, and Java is not in the
image.

`horstoeko/zugferd` ships the official EN16931 Schematron compiled to XSLT.
**It is XSLT 2.0, and PHP's libxslt implements 1.0 only**, so it cannot run
through `XSLTProcessor` — this was tried and is recorded so nobody tries it
again. **SaxonC-HE** (`saxonche`, pip) is an XSLT 3.0 processor with no JVM, and
the image already runs Python for WeasyPrint. One pip line and a 40-line script
buy the official verdict.

So: XSD through libxml **inside the transaction**, where it costs milliseconds
and guards the operation; the Schematron over golden fixtures **in the test
suite**, where its verdict is not ours.

This paid for itself immediately — it found two real defects in the first XML
this wave produced (§7.2).

> **Corrected 2026-09-28, after the first invoice issued in anger failed an
> external validator.** The measurement this section leans on was never taken.
> The Schematron costs **~145 ms**, against ~240 ms for the WeasyPrint render
> the lock is already held across — so „far too heavy to run while the
> Nummernkreis is locked" was an assumption, and a wrong one.
>
> It still does not run at issue time, but now by choice rather than by
> arithmetic: the runtime path stays free of a second subprocess, and a broken
> `saxonche` therefore cannot stop anyone invoicing. The guarantee is bought a
> different way — see §3.6.

**What still is not checked:** whether the container is a *conforming* PDF/A-3.
That needs veraPDF or the full KoSIT validator. Recorded as a gap.

### 3.4 Totals are stored; the groups are not

§6 says totals are stored on the document. Three columns — `net_total`,
`tax_total`, `gross_total` — in integer cents through `MoneyCast`.

The per-**Steuersatz** groups are **not** stored. They are derivable from
**Positionen** that are immutable after issue, so a stored copy could only ever
disagree with the lines it claims to sum. `Document::grossAmount()` reads the
stored figure once there is one and computes it while a draft; a test holds the
two to the same answer.

### 3.5 One audit event

§3.8 lists „issued" and „PDF generated" as separate events. They happen in one
transaction, one statement apart, and neither can exist without the other — two
rows for one fact. One `Issued` entry carries the path and the hash in its
details instead.

`AuditEvent` therefore has a single case. That reads thin and is honest: the
other events arrive with the operations that can produce them, which is what
keeps an entry from being written for something that did not happen.

### 3.6 The identifiers are checked where they are typed

**The rule this rests on: what the forms accept must be a subset of what
EN16931 accepts.** With the Schematron out of the runtime path, that is the
only thing standing between a typo and a frozen, rejected invoice.

The first invoice issued for real proved the gap. Its company's USt-IdNr read
`iuoiuoi`. The settings form took any string, `CheckReadiness` asked only
whether the field was non-blank, and XSD cannot express BR-CO-09 — so it issued,
froze the value into its Festschreibung, and produced a file the customer's
validator rejected. Nothing here can correct it: an issued Beleg is
unveränderlich and the Storno that would retract it is not built.

Three changes, in the order they catch things:

1. **`App\Rules\VatId`** on the company settings and the customer form. An ISO
   3166-1 alpha-2 prefix from the EU member states, plus the two substitutions
   the standard names — **EL** for Greece and **XI** for Northern Ireland — and
   the German check digit (ISO 7064 MOD 11,10). Foreign checksums are
   deliberately not encoded: 26 national algorithms go stale silently, and this
   application taxes German supplies.
2. **`App\Rules\TaxNumber`**, shape and length only. A Steuernummer carries no
   check digit and its format differs per Bundesland, so what is checked is what
   can be checked without guessing — enough to refuse `8989898`, which was the
   other value in that database. EN16931 imposes nothing here (BT-32 is free
   text under `schemeID="FC"`), so this is about the *document* being right
   rather than the XML being accepted.
3. **`CheckReadiness` asks whether an identifier is usable, not whether the
   field is filled.** Every identifier that is present must be valid — a good
   Steuernummer does not rescue a document that also carries a malformed
   USt-IdNr, because both print and both go into the XML. `ReadinessItem` gained
   a `reason`, so „already filled in, but wrong" reads differently from „fill
   this in".

Both models normalise on write, as `Company::iban()` already did: `DE 811 907
980`, `de811907980` and `DE-811.907.980` are one number and must not become
three.

**The test that carries this** is in `TaxIdentifierTest`: every shape of
identifier the rules accept is run through the official Schematron. Loosen a
rule past what EN16931 allows and it goes red there, rather than in a customer's
accounting software.

## 4. Data model

### 4.1 `documents`, added

| Column | Type | Notes |
|---|---|---|
| `number` | string(32), nullable | The Belegnummer as it prints. Was an integer |
| `due_on` | date, nullable | **Fälligkeitsdatum**, computed once at issue |
| `net_total`, `tax_total`, `gross_total` | bigint, nullable | Cents, via `MoneyCast` |
| `frozen_block` | jsonb, nullable | The **Festschreibung** |
| `pdf_path` | string, nullable | On `config('invoice.documents_disk')` |
| `pdf_sha256` | string(64), nullable | Tech stack §7.3 |

`unique(company_id, number)` is rebuilt over the new column.

**No `issued_at`.** The audit entry is the timestamp of the act, and a second
one could drift from it.

### 4.2 `audit_entries`

`id`, `document_id` (**`restrictOnDelete`**), `event`, `occurred_at`,
`actor_id` (nullable → `users`), `details` jsonb, timestamps;
`index(document_id, occurred_at)`.

Append-only, enforced on the model: `updating` and `deleting` both throw. The
value of an audit trail is exactly that it cannot be tidied up afterwards.

`restrictOnDelete` is a claim rather than caution. Only an Entwurf is deletable
and only issuing writes an entry, so no entry can ever belong to a deletable
document — and the database says so instead of a comment promising it.

**Keyed to a document, not to a polymorphic subject.** A **Mahnung** is not a
Beleg and will need its own trail; that is the wave that should generalise this.

### 4.3 The Festschreibung

`App\Documents\FrozenBlock`, a readonly DTO over `SellerIdentity` and
`BuyerAddress`, cast to jsonb by `App\Casts\FrozenBlockCast` — a plain cast, per
tech stack §6.3.

Small on purpose: there is no product catalogue, so a Position already carries
its own values. The **Zahlungsziel**, which §4 also names, is already its own
frozen column — putting it here too would give one value two homes.

The cast **refuses anything but a `FrozenBlock` on the way down**, and refuses
to read a block missing a required field on the way up. The column is the one
nothing may correct afterwards: `Document`'s guard refuses the update that would
fix a half-written block, so a silent null would mean an invoice printed with no
seller address on it.

## 5. `App\Actions\IssueDocument`

`__invoke(Document $document, ?User $actor = null): Document`.

**Before the transaction opens** — so prerequisites are „reported as a list, not
raised as an exception halfway through issuing" (§8.1):

1. Refuse unless the stored status is `draft`.
2. Refuse a Beleg with no Position.
3. `CheckReadiness`; on `canIssue() === false` throw `CompanyNotReady`, carrying
   the `Readiness` so the caller can name each blocker.

**Inside one transaction, in this order:**

1. `DrawNextNumber` — locks `number_ranges`, so everything after is inside the
   lock and a failure gives the number back.
2. The `FrozenBlock`, from the live models for the last time.
3. The totals and the `due_on`.
4. `forceFill` all of it in memory — the renderer and the XML builder both read
   the document, so it must be complete before either runs, and unsaved because
   the save has to happen last.
5. Render, build the XML, **validate it**, merge to PDF/A-3, hash the bytes.
6. **Write the file before the commit** (§7.2).
7. One `save()` flipping the status together with everything else.
8. The `AuditEntry`.

Step 7 is permitted because `Document`'s guard reads the **stored** status,
still `draft`. After that save nothing may change but the status.

Path: `companies/{company}/documents/{year}/{number}.pdf`, the year taken from
the draw rather than from `issued_on` so the folder agrees with the number.

## 6. The printed Beleg

`resources/views/documents/invoice.blade.php` and its paged stylesheet. CSS
Paged Media: `@page` at A4, a running element for the identity footer,
`counter(page)`/`counter(pages)`.

One house template for every company; what differs is the logo and the identity
block, substituted **from the Festschreibung**. The logo is embedded as a
`data:` URI rather than linked, because the renderer has no HTTP context and the
logos disk may be object storage.

`App\Pdf\RenderInvoicePdf` returns bytes rather than writing a file: the file is
written once, at the end, and a render that produced one would leave litter on
every rollback.

## 7. The XML

`App\Zugferd\BuildZugferdXml` maps this domain onto EN16931, reading both
parties from the Festschreibung so the page and the embedded XML cannot describe
different invoices. UNTDID 1001 **380**. `Unit` is backed by the UN/ECE code and
`tax_rate` by basis points, so neither needs a lookup (ADR 0003).

A §19 company's 0 % group is category **E** with an exemption reason, not **Z**:
§19 UStG is a genuine exemption the recipient has to be told about, while Z says
the supply is taxable at zero, which is a different statement.

### 7.1 `ValidateZugferdXml` takes the XML, not the builder

`horstoeko`'s own `ZugferdXsdValidator` makes the same libxml call, but only
against a `ZugferdDocument`, whose constructor is `final` — nothing can hand it
invalid XML and watch it refuse. A validator that cannot be shown failing is
decoration, so this one takes a string and is tested with input the real
pipeline cannot produce. The XSD path still comes from the library.

### 7.2 What the Schematron caught

Two defects in the Leistungszeitraum case, both invisible to the XSD and to
every assertion this repo would have written:

- **BR-FX-EN-04**: with no BT-72, the country of delivery (BT-80) is required.
- **PEPPOL-EN16931-R008**: `ApplicableHeaderTradeDelivery` was emitted empty.

Both are fixed by naming a ShipTo party and its country when a Beleg carries a
period rather than a day. The party has to be named *before* its address, or the
address has nothing to hang on and the element stays empty — which is how it was
first written.

### 7.3 `--pdf-version 1.4`, and why it is load-bearing

`horstoeko` builds the PDF/A-3 with FPDI's free parser, which **refuses a
compressed cross-reference stream** — what WeasyPrint writes from PDF 1.5 on.
The default configuration therefore fails every Ausstellvorgang at the merge,
with a `CrossReferenceException` and nothing wrong with the rendered page.

`config/laravel-pdf.php` forces `pdf-version` to 1.4, which emits a classic xref
table. The version set there is only the intermediate's; the delivered file
carries whatever the ZUGFeRD merge writes.

## 8. Screens

- **Rechnung ausstellen** — the modal *is* the Bereitschaftsprüfung. A company
  that cannot issue sees what is missing, a link to Einstellungen, and **no
  submit button**; a button that leads to an error is worse than no button. A
  company that can issue is told what the click makes irreversible.
- **The detail page of an issued Beleg** reads its recipient from the
  Festschreibung — heading, subheading and Beleg card — so a customer who
  renames themselves tomorrow does not change what this page says the invoice
  was addressed to. It offers **PDF herunterladen** and neither Bearbeiten nor
  Löschen.
- **Verlauf** lists the audit entries over the derived „Erstellt" line.
- **The list** gains the Belegnummer, the Fälligkeitsdatum, an **Überfällig**
  badge and a status filter — the filter earns its place now that there is more
  than one status to filter by. Search matches the Belegnummer too.
- **The edit page refuses a non-draft with a 404**, which also closes the hole
  the drafts wave flagged: `HandlesLineItems` deleted Positionen with a mass
  delete that fired no model events and therefore walked past `LineItem`'s
  guard. It now deletes through model instances.
- **The dashboard's third step** links to the invoice create page. It had said
  „Rechnungen gibt es noch nicht" for a wave after they did.

## 9. Testing

Each names what makes it fail.

- **A forced render failure consumes no number and leaves a draft** — a real
  failure of the real renderer, not a double. Removing `DB::transaction` turns
  it red while everything else stays green; this was verified by doing it.
- **Two forked processes issue two drafts of one company** and get distinct,
  consecutive Belegnummern, with the lock held across a real render. Breaking
  `lockForUpdate()` turns three concurrency tests red; also verified.
- **The freeze does not follow master data** — rename the company and move the
  customer after issuing, and the block, the page and the file are unchanged.
- **The golden fixtures satisfy the official EN16931 Schematron**, plus one
  document broken on purpose so the validator can be seen reporting.
- **The merged PDF reads back** through `ZugferdDocumentPdfReader` to the same
  number and figures as the row — catching a merge that silently dropped the
  attachment.
- **The rendered page** through `pdftotext` and `pdffonts`, and **looked at**.
- The cast refuses a half-written block; an `AuditEntry` refuses update and
  delete; `CheckReadiness` refuses by name and consumes nothing.

**Deliberately not written:** a separate rollback test for XSD failure — it sits
between the number draw and the write, so the render test already covers that
path, and producing schema-invalid XML through the real pipeline would assert
something about the test rather than about issuing.

## 10. Known gaps this wave opens

- **PDF/A-3 conformance is unverified.** The file declares itself correctly and
  reads back as ZUGFeRD; whether it *conforms* needs veraPDF or KoSIT.
- **Only data the forms guard is guarded.** The official rules do not run at
  issue time, so the guarantee is the subset argument of §3.6 and holds only for
  the fields it covers. A rule EN16931 enforces on a field nothing validates
  would reach a frozen PDF exactly as BR-CO-09 did. The measurement in §3.3
  says the runtime check would cost ~145 ms if that trade is ever revisited.
- **`issued_on` is respected as typed.** A draft dated 2025-12-31 issued in
  January keeps its 2025 Ausstellungsdatum while the Nummernkreis, which counts
  in real time, gives it a 2026 number.
- **The country is `DE` for everyone** (§3.2).
- **`audit_entries` is keyed to a document**, not to a polymorphic subject.
- **No `issued_at` column**; the audit entry carries the moment.

## 11. Out of scope

Storno and Teilstorno, Zahlungen and the **offener Betrag**, **versenden** and
the E-Mail tab, Mahnungen, the customer page's three stat tiles (they count
payments), the period export, Duplicate, and the **Berichtigung**.

This spec is dated and is not itself updated when the stack later moves.
