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
    .app-company-avatar { display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; width: 1.75rem; height: 1.75rem; border-radius: 0.5rem; border: 1px solid var(--gray-200); background: var(--gray-50); color: var(--gray-600); font-size: 0.6875rem; font-weight: 600; letter-spacing: 0.02em }
    .app-company-avatar-current { border-color: var(--primary-300); background: var(--primary-50); color: var(--primary-700) }
    .app-company-option { display: flex; align-items: center; gap: 0.75rem; width: 100% }
    .app-company-option-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis }
    .app-company-option-check { width: 1.25rem; height: 1.25rem; flex-shrink: 0; color: var(--primary-600) }
    .app-company-menu-label { font-size: 0.75rem; font-weight: 600; letter-spacing: 0.04em; color: var(--gray-400) }
    .dark .app-company-avatar { border-color: var(--gray-700); background: var(--gray-800); color: var(--gray-300) }
    .dark .app-company-avatar-current { border-color: var(--primary-700); background: color-mix(in oklab, var(--primary-500) 15%, transparent); color: var(--primary-400) }
    .dark .app-company-option-check { color: var(--primary-400) }
</style>
