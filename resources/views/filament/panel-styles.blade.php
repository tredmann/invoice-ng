{{--
    Page-level CSS, injected at STYLES_AFTER alongside topbar-styles.

    Two files rather than one because they hold different things: the top bar's
    stylesheet is about the chrome, this one about what pages put on the screen.
    Neither can be avoided — there is no frontend build (CLAUDE.md), so only
    classes in Filament's shipped CSS exist and anything beyond them is written
    here. Colours come from Filament's own variables rather than hex, so the
    panel's palette stays one decision, and Filament marks dark mode with the
    dark class on <html>.
--}}
<style>
    /* The Nummernkreis preview. Boxed because it is the one thing on that tab
       that is an answer rather than a setting: a quiet label over a large
       value, which is the opposite emphasis to a Filament section heading. */
    .app-number-preview { display: flex; flex-direction: column; gap: 0.25rem; padding: 1rem; border: 1px solid var(--gray-200); border-radius: 0.5rem; background: var(--gray-50) }
    .app-number-preview-label { font-size: 0.75rem; font-weight: 500; color: var(--gray-500) }
    .app-number-preview-value { font-size: 1.375rem; font-weight: 600; line-height: 1.2; color: var(--gray-950) }
    .dark .app-number-preview { border-color: var(--gray-700); background: var(--gray-800) }
    .dark .app-number-preview-label { color: var(--gray-400) }
    .dark .app-number-preview-value { color: var(--color-white) }

    /* The totals under the Positionen: label left, figure right, the total
       set apart by a rule above it, as the mockup draws it. */
    .app-invoice-totals { display: flex; flex-direction: column; gap: 0.375rem; margin-inline-start: auto; width: 100%; max-width: 24rem; font-size: 0.875rem }
    .app-invoice-totals > div { display: flex; justify-content: space-between; gap: 2rem; color: var(--gray-600) }
    .app-invoice-totals > .app-invoice-total { margin-top: 0.375rem; padding-top: 0.625rem; border-top: 1px solid var(--gray-200); font-size: 1rem; font-weight: 600; color: var(--gray-950) }
    .dark .app-invoice-totals > div { color: var(--gray-400) }
    .dark .app-invoice-totals > .app-invoice-total { border-top-color: var(--gray-700); color: var(--color-white) }

    /* The Beleg card's right column and the Positionen table on a document's
       detail page: label left, value right, as invoice-ui-decisions settled
       for document cards — the opposite of the customer page, whose pairs
       stack. */
    .app-beleg-rows { display: flex; flex-direction: column; gap: 0.625rem; font-size: 0.875rem }
    .app-beleg-rows > div { display: flex; justify-content: space-between; gap: 1.5rem }
    .app-beleg-rows dt { color: var(--gray-500) }
    .app-beleg-rows dd { margin: 0; font-weight: 500; color: var(--gray-950); text-align: end }
    .dark .app-beleg-rows dt { color: var(--gray-400) }
    .dark .app-beleg-rows dd { color: var(--color-white) }

    .app-positions { width: 100%; border-collapse: collapse; font-size: 0.875rem }
    .app-positions th { padding: 0 0.5rem 0.5rem; font-weight: 400; color: var(--gray-500); text-align: start; border-bottom: 1px solid var(--gray-200) }
    .app-positions td { padding: 0.75rem 0.5rem; vertical-align: top; color: var(--gray-950) }
    .app-positions tbody tr + tr td { border-top: 1px solid var(--gray-100) }
    .app-positions .app-positions-end { text-align: end; white-space: nowrap }
    .app-positions .app-positions-index { color: var(--gray-400) }
    .app-positions .app-positions-note { display: block; margin-top: 0.125rem; color: var(--gray-500) }
    .dark .app-positions th { color: var(--gray-400); border-bottom-color: var(--gray-700) }
    .dark .app-positions td { color: var(--color-white) }
    .dark .app-positions tbody tr + tr td { border-top-color: var(--gray-800) }
    .dark .app-positions .app-positions-note { color: var(--gray-400) }

    /* Filament renders a Text component as an inline-block span, so a block
       inside one shrinks to its contents — which left the invoice totals at
       225px in a 1068px card. Widened only where our own totals are inside. */
    .fi-sc-text:has(> .app-invoice-totals) { display: block; width: 100% }

    /* Pos. numbers as a counter over the repeater's rows, so dragging a
       Position into another order renumbers them without anything in the
       form state knowing its own index. */
    .app-positions-repeater tbody { counter-reset: app-position }
    .app-positions-repeater tbody > tr { counter-increment: app-position }
    /* Counted from the end, because the start moves: Filament adds a leading
       drag-handle cell only once there are two rows to reorder, so the Pos.
       cell is first with one row and second with more. What never changes is
       what follows it — Bezeichnung, Menge, Einheit, Einzelpreis, Steuer,
       Netto, Beschreibung and the actions cell, eight in all. */
    .app-positions-repeater tbody > tr > td:nth-last-child(9) { color: var(--gray-400); font-size: 0.875rem; text-align: center; line-height: 2.25rem }
    .app-positions-repeater tbody > tr > td:nth-last-child(9)::before { content: counter(app-position) }
    /* The cell needs a component to exist at all, but the number is the
       ::before — left visible, the empty placeholder adds its own line box
       and makes this the tallest cell in the row. */
    .app-positions-repeater tbody > tr > td:nth-last-child(9) > * { display: none }
    .dark .app-positions-repeater tbody > tr > td:nth-last-child(9) { color: var(--gray-500) }

    /* The Positionen row is a grid rather than a table row.
       An HTML cell can only span columns with colspan, which Filament's table
       repeater never emits — it writes one <td> per field. Laying the header
       and the rows out on one shared template gets the same result: every
       field keeps its column, and Beschreibung, which has no track of its
       own, is placed on a second line across Bezeichnung to Steuer.
       align-items: start is what puts the numeric fields level with
       Bezeichnung instead of centring them against both its lines. */
    .app-positions-repeater thead > tr,
    .app-positions-repeater tbody > tr {
        display: grid;
        grid-template-columns: 3rem minmax(0, 1fr) 6rem 8rem 8rem 7rem 7rem auto;
        column-gap: 0.5rem;
        align-items: start;
    }

    /* Filament adds the drag-handle cell only once there are two rows to
       reorder, to the header and the rows alike — so the template gains a
       leading track at the same moment for both. */
    .app-positions-repeater table:has(tbody > tr + tr) thead > tr,
    .app-positions-repeater table:has(tbody > tr + tr) tbody > tr {
        grid-template-columns: 2.5rem 3rem minmax(0, 1fr) 6rem 8rem 8rem 7rem 7rem auto;
    }

    /* Beschreibung: no header, second line, spanning the five fields above. */
    .app-positions-repeater thead > tr > th:nth-last-child(2) { display: none }
    .app-positions-repeater tbody > tr > td:nth-last-child(2) { grid-row: 2; grid-column: 2 / span 5 }
    .app-positions-repeater table:has(tbody > tr + tr) tbody > tr > td:nth-last-child(2) { grid-column: 3 / span 5 }

    /* Numbers line up on the right, the way they do on the document. */
    .app-input-end { text-align: end }
</style>
