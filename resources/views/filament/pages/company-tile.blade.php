{{--
    One company on the picker. The whole card is the link.

    overflow-wrap and min-width let a long single-word name (a German compound)
    wrap inside the card: Filament's section heading sets no overflow-wrap, and
    a flex item will not shrink below its longest word.
--}}
<a href="{{ $url }}" style="display: block; min-width: 0; overflow-wrap: anywhere">
    <x-filament::section :heading="$name" :description="$legalForm" />
</a>
