{{--
    The company switcher in the top bar. It only switches: its dropdown lists
    the user's active companies and nothing else (company-picker spec §2.1).

    Rendered twice (spike findings): after the brand for desktop — Filament
    hides that whole area below 64rem — and before the user menu for phones,
    hidden from 64rem by the one CSS rule the panel adds (STYLES_AFTER). The
    phone copy leaves out the fi-tenant-menu class, which Filament hides in
    the top bar below 64rem, and shows only the avatar: a name there wraps
    into several lines beside the user menu.
--}}
@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\Company> $companies */
    /** @var \App\Models\Company|null $current */
@endphp

@if ($current !== null || $companies->isNotEmpty())
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
@endif
