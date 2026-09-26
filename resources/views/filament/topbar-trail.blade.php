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

{{-- With no active company there is nothing to switch to — even inside an
     archived company opened by its URL (spec §2.1). --}}
@if ($companies->isNotEmpty())
    <div @if ($variant === 'desktop') data-topbar-column @endif>
        <div class="app-topbar-trail">
            <div data-company-switcher="{{ $variant }}">
                <x-filament::dropdown placement="bottom-start" size @class(['fi-tenant-menu' => $variant === 'desktop'])>
                    <x-slot name="trigger">
                        <button type="button" class="fi-tenant-menu-trigger" aria-label="{{ $current?->name ?? __('company.picker.title') }}">
                            @if ($current)
                                <x-filament-panels::avatar.tenant :tenant="$current" />
                            @else
                                {{-- Inline style: the trigger's .fi-icon rule would push a
                                     bare icon to the end with margin-inline-start: auto. --}}
                                <span class="fi-avatar fi-tenant-avatar" style="display: flex; align-items: center; justify-content: center; background: color-mix(in oklab, currentColor 8%, transparent)">
                                    <x-filament::icon icon="heroicon-o-building-office-2" style="margin: 0" />
                                </span>
                            @endif
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
                        <x-filament::dropdown.list>
                            @foreach ($companies as $company)
                                @php($isCurrent = $current?->is($company) ?? false)
                                <x-filament::dropdown.list.item
                                    tag="a"
                                    :href="filament()->getUrl($company)"
                                    :image="filament()->getTenantAvatarUrl($company)"
                                    :color="$isCurrent ? 'primary' : 'gray'"
                                    :data-current-company="$isCurrent"
                                >
                                    {{ $company->name }}
                                </x-filament::dropdown.list.item>
                            @endforeach
                        </x-filament::dropdown.list>
                    @endif
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
