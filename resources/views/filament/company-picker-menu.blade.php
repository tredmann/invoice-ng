{{--
    Stands in for Filament's company menu on /admin, where no company is
    current and Filament's own menu cannot render. Built from Filament's
    dropdown components and its menu classes so it looks the same.
--}}
<div class="fi-sidebar-header-controls" data-company-picker-menu>
    <x-filament::dropdown placement="bottom-start" size class="fi-tenant-menu">
        <x-slot name="trigger">
            <button type="button" class="fi-tenant-menu-trigger">
                {{-- An avatar-sized box, like Filament's tenant avatar. Inline
                     style because the trigger's .fi-icon rule pushes a bare
                     icon to the end with margin-inline-start: auto. --}}
                <span class="fi-avatar fi-tenant-avatar" style="display: flex; align-items: center; justify-content: center; background: color-mix(in oklab, currentColor 8%, transparent)">
                    <x-filament::icon icon="heroicon-o-building-office-2" style="margin: 0" />
                </span>
                <span class="fi-tenant-menu-trigger-text">
                    <span class="fi-tenant-menu-trigger-tenant-name">{{ __('company.picker.title') }}</span>
                </span>
                <x-filament::icon icon="heroicon-m-chevron-down" />
            </button>
        </x-slot>

        @if ($companies->isNotEmpty())
            <x-filament::dropdown.list>
                @foreach ($companies as $company)
                    <x-filament::dropdown.list.item tag="a" :href="filament()->getUrl($company)">
                        {{ $company->name }}
                    </x-filament::dropdown.list.item>
                @endforeach
            </x-filament::dropdown.list>
        @endif

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item tag="a" :href="filament()->getTenantRegistrationUrl()" icon="heroicon-m-plus">
                {{ __('company.actions.create') }}
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
</div>
