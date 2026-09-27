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
       inside one shrinks to its contents instead of filling its column. It
       left the invoice totals at 225px in a 1068px card, and the Beleg rows
       ending 115px short of the card's edge — which reads as uneven padding
       rather than as a width. Every class below is markup of ours placed in a
       Text and meant to fill. */
    .fi-sc-text:has(> .app-invoice-totals),
    .fi-sc-text:has(> .app-beleg-rows),
    .fi-sc-text:has(> .app-status),
    .fi-sc-text:has(> .app-history),
    .fi-sc-text:has(> .app-positions) { display: block; width: 100% }

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

    /* And a gap after that rule before the sums. The rows sit tight against
       their own rules and take their breathing room from the cell padding,
       so without this the sums start closer to the last Position than the
       Positionen do to each other. 14px, off the board. */
    .app-positions-ruled + .app-invoice-totals { margin-top: 0.875rem }

    /* Numbers line up on the right, the way they do on the document. */
    .app-input-end { text-align: end }

    /* ---------------------------------------------------------------------
       Die Übersicht (§9). Jede Zahl unten ist am Penpot-Board gemessen, nicht
       geschätzt — die Kacheln 20 Innenabstand bei 20 Abstand, die Karten 24,
       die Tabellenzeilen 12. Filaments Rahmen stimmt bereits: .fi-main hat
       32 Seitenabstand und der Schema-Container 24 Abstand, genau wie das
       Board, also wird hier nichts davon nachgebaut.
       --------------------------------------------------------------------- */

    /* Die Kennzahlenreihe. Ein eigenes Raster statt vier Sections: eine
       Section betont die Überschrift und hält den Inhalt auf Abstand, die
       Kachel macht das Gegenteil — stille Beschriftung, große Zahl, 8
       dazwischen. */
    .app-stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1.25rem }
    .app-stats-tile { display: flex; flex-direction: column; gap: 0.5rem; padding: 1.25rem; border: 1px solid var(--gray-200); border-radius: 0.75rem; background: var(--color-white) }
    .app-stats-label { display: flex; align-items: center; gap: 0.375rem; margin: 0; font-size: 0.8125rem; font-weight: 500; color: var(--gray-500) }
    .app-stats-value { margin: 0; font-size: 1.625rem; font-weight: 600; line-height: 1.4; color: var(--gray-950) }
    .app-stats-note { display: flex; align-items: center; gap: 0.3125rem; margin: 0; font-size: 0.75rem; color: var(--gray-500) }
    .app-stats-arrow { width: 0.875rem; height: 0.875rem; flex: none }
    .app-stats-up { font-weight: 500; color: var(--success-700) }
    .app-stats-down { font-weight: 500; color: var(--danger-700) }
    /* Rot erst, wenn etwas überfällig ist — siehe stats.blade.php. */
    .app-stats-danger .app-stats-label, .app-stats-danger .app-stats-value { color: var(--danger-700) }
    .dark .app-stats-tile { border-color: var(--gray-700); background: var(--gray-900) }
    .dark .app-stats-label, .dark .app-stats-note { color: var(--gray-400) }
    .dark .app-stats-value { color: var(--color-white) }
    .dark .app-stats-up { color: var(--success-400) }
    .dark .app-stats-down, .dark .app-stats-danger .app-stats-label, .dark .app-stats-danger .app-stats-value { color: var(--danger-400) }

    /* Das Board zeichnet nur 1440 breit. Vier Kacheln nebeneinander brauchen
       gut 1000; darunter zwei, auf dem Telefon eine. */
    @media (max-width: 1279px) { .app-stats { grid-template-columns: repeat(2, minmax(0, 1fr)) } }
    @media (max-width: 639px) { .app-stats { grid-template-columns: minmax(0, 1fr) } }

    /* Zwei Korrekturen am Kartenrhythmus. Filament setzt 16/24 auf den Kopf
       und noch einmal 24 auf den Inhalt, also 40 zwischen Unterzeile und
       Inhalt — das Board zeichnet 16. Genau das ist der Grund, aus dem die
       letzten beiden Seiten vom Board abgedriftet sind: nicht eine falsche
       Zahl, sondern eine, die nie gesetzt wurde. */
    /* extraAttributes() einer Section landet auf einem *Rahmen* um die Karte
       (`div.app-card-plain.fi-sc-section`), nicht auf `.fi-section` selbst —
       jeder Selektor hier muss also eine Ebene tiefer greifen. Ohne das
       `> .fi-section` traf keine dieser Regeln, und zwar lautlos: die Seite
       sah bloß nach Filaments Voreinstellung aus. Genau so driftet eine Seite
       vom Board ab. */
    .app-card-snug > .fi-section > .fi-section-header { padding: 1.25rem 1.25rem 0 }
    .app-card-snug > .fi-section > .fi-section-content-ctn > .fi-section-content { padding: 0.875rem 1.25rem 1.25rem }
    .app-card-plain > .fi-section > .fi-section-header { padding: 1.5rem 1.5rem 0 }
    .app-card-plain > .fi-section > .fi-section-content-ctn > .fi-section-content { padding: 1rem 1.5rem 1.5rem }
    .fi-wi-chart > .fi-section > .fi-section-header { padding: 1.5rem 1.5rem 0 }
    .fi-wi-chart > .fi-section > .fi-section-content-ctn > .fi-section-content { padding: 1rem 1.5rem 1.5rem }

    /* Und die Linie unter dem Kartenkopf fort — das Board zeichnet keine, und
       mit ihr läse sich die Karte als zwei Blöcke statt als einer. Die
       Selektoren tragen Filaments eigene :not()-Glieder, weil sie ihn bei
       gleicher Spezifität schlagen müssen; dieses Stylesheet kommt später. */
    .app-card-snug > .fi-section:not(.fi-aside).fi-section-has-header:not(.fi-collapsed) > .fi-section-content-ctn,
    .app-card-plain > .fi-section:not(.fi-aside).fi-section-has-header:not(.fi-collapsed) > .fi-section-content-ctn,
    .fi-wi-chart > .fi-section:not(.fi-aside).fi-section-has-header:not(.fi-collapsed) > .fi-section-content-ctn { border-top-width: 0 }

    /* Der Arbeitsvorrat: Name über Datum, Betrag rechts, Haarlinie dazwischen.
       Die Zeile ist ein Link auf den Entwurf, sieht aber nicht wie einer aus —
       die ganze Karte wäre sonst blau unterstrichen. */
    .app-drafts { display: flex; flex-direction: column; gap: 0.875rem }
    .app-drafts-row { display: flex; align-items: center; justify-content: space-between; gap: 0.625rem; color: inherit; text-decoration: none }
    .app-drafts-row + .app-drafts-row { padding-top: 0.875rem; border-top: 1px solid var(--gray-100) }
    .app-drafts-row:hover .app-drafts-name { color: var(--primary-600) }
    .app-drafts-angaben { display: flex; flex-direction: column; gap: 0.125rem; min-width: 0 }
    .app-drafts-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.8125rem; font-weight: 500; color: var(--gray-950) }
    .app-drafts-date { font-size: 0.6875rem; color: var(--gray-500) }
    .app-drafts-amount { flex: none; font-size: 0.8125rem; font-weight: 500; color: var(--gray-950) }
    .app-drafts-empty { margin: 0; font-size: 0.8125rem; color: var(--gray-500) }
    .dark .app-drafts-row + .app-drafts-row { border-top-color: var(--gray-800) }
    .dark .app-drafts-name, .dark .app-drafts-amount { color: var(--color-white) }
    .dark .app-drafts-date, .dark .app-drafts-empty { color: var(--gray-400) }

    .app-cardlink { display: inline-flex; align-items: center; gap: 0.375rem; margin-top: 0.25rem; font-size: 0.8125rem; font-weight: 600; color: var(--primary-600); text-decoration: none }
    .app-cardlink-icon { width: 0.8125rem; height: 0.8125rem }
    .dark .app-cardlink { color: var(--primary-400) }

    /* Die überfälligen Rechnungen. Die Spaltenbreiten stehen auf dem Board;
       der Betrag rechtsbündig, weil Zahlen untereinander gelesen werden. */
    .app-overdue-scroll { overflow-x: auto }
    .app-overdue-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem }
    .app-overdue-table th { padding: 0 0 0.5rem; font-size: 0.75rem; font-weight: 600; color: var(--gray-500); text-align: start; border-bottom: 1px solid var(--gray-200); white-space: nowrap }
    .app-overdue-table td { padding: 0.75rem 0; color: var(--gray-800); white-space: nowrap }
    .app-overdue-table tbody tr + tr td { border-top: 1px solid var(--gray-100) }
    .app-overdue-table th + th, .app-overdue-table td + td { padding-inline-start: 1rem }
    .app-overdue-number { width: 9.375rem }
    .app-overdue-number a { font-weight: 500; color: var(--gray-950); text-decoration: none }
    .app-overdue-number a:hover { color: var(--primary-600) }
    .app-overdue-customer { width: auto; white-space: normal }
    .app-overdue-due { width: 12.5rem; color: var(--danger-700) }
    .app-overdue-table td.app-overdue-due { color: var(--danger-700) }
    .app-overdue-total { width: 7.5rem; text-align: end; font-weight: 500; color: var(--gray-950) }
    .app-overdue-empty { margin: 0; font-size: 0.8125rem; color: var(--gray-500) }
    .dark .app-overdue-table th { color: var(--gray-400); border-bottom-color: var(--gray-700) }
    .dark .app-overdue-table td { color: var(--gray-300) }
    .dark .app-overdue-table tbody tr + tr td { border-top-color: var(--gray-800) }
    .dark .app-overdue-number a, .dark .app-overdue-total { color: var(--color-white) }
    .dark .app-overdue-table td.app-overdue-due { color: var(--danger-400) }
    .dark .app-overdue-empty { color: var(--gray-400) }
</style>
