{{--
    The top bar's company switcher and, after it, the trail of the page
    (top-bar trail spec §2). The switcher is the trail's first crumb.

    Rendered twice (company-picker spike findings): after the brand for
    desktop — Filament hides that whole area below 64rem — and before the
    user menu for phones, hidden from 64rem by topbar-styles. The phone copy
    leaves out the fi-tenant-menu class, which Filament hides in the top bar
    below 64rem, shows only the avatar, and carries no trail: beside the user
    menu there is no room for one.

    On desktop the whole of it sits in data-topbar-column, which
    topbar-styles lays over the page's content column, so the switcher starts
    where the heading below it starts.
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Company> $companies */
    /** @var \App\Models\Company|null $current */
    /** @var list<array{label: string, url: string|null}> $trail */
@endphp

{{-- Hidden only where there is nothing to switch to and no company to name:
     on /admin with no active company (spec §2.3). Inside an archived company
     opened by its URL it shows, because Firmen verwalten and Neue Firma make
     the dropdown worth opening (spec §2.5). --}}
@if ($companies->isNotEmpty() || $current !== null)
    <div @if ($variant === 'desktop') data-topbar-column @endif>
        <div class="app-topbar-trail">
            <div data-company-switcher="{{ $variant }}">
                <x-filament::dropdown placement="bottom-start" size width="xs" @class(['fi-tenant-menu' => $variant === 'desktop'])>
                    <x-slot name="trigger">
                        <button type="button" class="fi-tenant-menu-trigger" aria-label="{{ $current?->name ?? __('company.picker.title') }}">
                            <span class="app-company-avatar">
                                @if ($current)
                                    {{ $current->initials() }}
                                @else
                                    {{-- Inline style: the trigger's .fi-icon rule would push a
                                         bare icon to the end with margin-inline-start: auto. --}}
                                    <x-filament::icon icon="heroicon-o-building-office-2" style="margin: 0" />
                                @endif
                            </span>
                            @if ($variant === 'desktop')
                                <span class="fi-tenant-menu-trigger-text">
                                    <span class="fi-tenant-menu-trigger-tenant-name">
                                        {{ $current?->name ?? __('company.picker.title') }}
                                    </span>
                                </span>
                            @endif
                            <x-filament::icon icon="heroicon-m-chevron-down" />
                        </button>
                    </x-slot>

                    @if ($companies->isNotEmpty())
                        <x-filament::dropdown.header class="app-company-menu-label">
                            {{ __('company.switcher.label') }}
                        </x-filament::dropdown.header>

                        <x-filament::dropdown.list>
                            @foreach ($companies as $company)
                                @php($isCurrent = $current?->is($company) ?? false)
                                <x-filament::dropdown.list.item
                                    tag="a"
                                    :href="filament()->getUrl($company)"
                                    :color="$isCurrent ? 'primary' : 'gray'"
                                    :data-current-company="$isCurrent"
                                >
                                    <span class="app-company-option">
                                        <span @class(['app-company-avatar', 'app-company-avatar-current' => $isCurrent])>{{ $company->initials() }}</span>
                                        <span class="app-company-option-name">{{ $company->name }}</span>
                                        @if ($isCurrent)
                                            <x-filament::icon icon="heroicon-m-check" class="app-company-option-check" />
                                        @endif
                                    </span>
                                </x-filament::dropdown.list.item>
                            @endforeach
                        </x-filament::dropdown.list>
                    @endif

                    {{-- A second list: Filament draws the divider between lists. --}}
                    <x-filament::dropdown.list>
                        {{-- Left out on /admin, where it would link to the page itself. --}}
                        @if ($current)
                            <x-filament::dropdown.list.item tag="a" :href="filament()->getHomeUrl()" icon="heroicon-o-building-office-2">
                                {{ __('company.switcher.manage') }}
                            </x-filament::dropdown.list.item>
                        @endif
                        <x-filament::dropdown.list.item tag="a" :href="filament()->getTenantRegistrationUrl()" icon="heroicon-o-plus">
                            {{ __('company.actions.create') }}
                        </x-filament::dropdown.list.item>
                    </x-filament::dropdown.list>
                </x-filament::dropdown>
            </div>

            @if ($trail !== [])
                <ol data-topbar-trail class="app-trail" aria-label="{{ __('layout.trail') }}">
                    @foreach ($trail as $crumb)
                        <li class="app-trail-item">
                            <x-filament::icon icon="heroicon-m-chevron-right" class="app-trail-separator" />
                            @if ($crumb['url'] !== null)
                                <a href="{{ $crumb['url'] }}" class="app-trail-link">{{ $crumb['label'] }}</a>
                            @else
                                <span aria-current="page">{{ $crumb['label'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
@endif
