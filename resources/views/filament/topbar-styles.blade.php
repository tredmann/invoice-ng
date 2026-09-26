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
