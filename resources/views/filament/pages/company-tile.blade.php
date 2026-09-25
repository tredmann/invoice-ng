{{-- One company on the picker. The whole card is the link. --}}
<a href="{{ $url }}" style="display: block">
    <x-filament::section :heading="$name" :description="$legalForm" />
</a>
