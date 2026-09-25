<x-filament-panels::page.simple>
    <div style="display: flex; justify-content: center">
        <x-filament::button tag="a" :href="$this->getRegistrationUrl()" icon="heroicon-o-plus">
            {{ __('company.actions.create') }}
        </x-filament::button>
    </div>

    {{-- Filament ships pre-built CSS and there is no frontend build, so a
         Tailwind class Filament does not already use would never be compiled.
         The grid is therefore an inline style. --}}
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(14rem, 1fr)); gap: 1rem">
        @foreach ($this->getCompanies() as $company)
            <a href="{{ $this->getCompanyUrl($company) }}" style="display: block">
                <x-filament::section :heading="$company->name" :description="$company->legal_form->getLabel()" />
            </a>
        @endforeach
    </div>
</x-filament-panels::page.simple>
