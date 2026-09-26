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
</style>
