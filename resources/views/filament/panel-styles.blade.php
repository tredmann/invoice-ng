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
       Netto and the actions cell, seven in all. */
    .app-positions-repeater tbody > tr > td:nth-last-child(8) { color: var(--gray-400); font-size: 0.875rem; text-align: center; line-height: 2.25rem }
    .app-positions-repeater tbody > tr > td:nth-last-child(8)::before { content: counter(app-position) }
    .dark .app-positions-repeater tbody > tr > td:nth-last-child(8) { color: var(--gray-500) }

    /* The drag handle and the delete button sit in cells Filament adds
       itself, so they have no TableColumn to be aligned through — they are
       the only two left centring against a two-line row. */
    .app-positions-repeater tbody > tr > td:first-child,
    .app-positions-repeater tbody > tr > td:last-child { vertical-align: top }
    .app-positions-repeater tbody > tr > td:last-child .fi-fo-table-repeater-actions { align-items: flex-start }

    /* The computed Netto is a Text, which has no alignment of its own — the
       column's alignEnd() reaches the header and stops there. It is the cell
       before the actions one, with or without a drag handle. */
    .app-positions-repeater tbody > tr > td:nth-last-child(2) { text-align: end }

    /* The Status card: a label over its value, three times. Measured off the
       board — label 12px/500 in gray-500, the Betrag 20px/600, a quarter of a
       line between a label and its value and a full line between the pairs.
       A schema would put the same gap everywhere, which is what made this
       card look airy. */
    .app-status { display: flex; flex-direction: column; gap: 1rem; margin: 0 }
    .app-status > div { display: flex; flex-direction: column; gap: 0.25rem; align-items: flex-start }
    .app-status dt { font-size: 0.75rem; font-weight: 500; color: var(--gray-500) }
    .app-status dd { margin: 0; font-size: 0.875rem; color: var(--gray-950) }
    .app-status .app-status-amount { font-size: 1.25rem; font-weight: 600; line-height: 1.4 }
    .dark .app-status dt { color: var(--gray-400) }
    .dark .app-status dd { color: var(--color-white) }

    /* The Verlauf: a marker, then the event over its timestamp. */
    .app-history { display: flex; flex-direction: column; gap: 0.875rem; margin: 0; padding: 0; list-style: none }
    .app-history > li { display: flex; align-items: flex-start; gap: 0.625rem }
    .app-history-dot { flex: none; width: 0.5rem; height: 0.5rem; margin-top: 0.375rem; border-radius: 9999px; background: var(--primary-500) }
    .app-history > li > div { display: flex; flex-direction: column; gap: 0.125rem; min-width: 0 }
    .app-history-event { font-size: 0.875rem; font-weight: 500; color: var(--gray-950) }
    .app-history-when { font-size: 0.75rem; color: var(--gray-500) }
    .dark .app-history-event { color: var(--color-white) }
    .dark .app-history-when { color: var(--gray-400) }

    /* The Positionen table closes with a rule before the sums, as the board
       draws it. Only on a document's own table — the customer page reuses
       these styles for a list that has nothing after it. */
    .app-positions-ruled { border-bottom: 1px solid var(--gray-200) }
    .dark .app-positions-ruled { border-bottom-color: var(--gray-700) }

    /* Numbers line up on the right, the way they do on the document. */
    .app-input-end { text-align: end }
</style>
