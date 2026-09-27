{{--
    Der Arbeitsvorrat: die drei neuesten Entwürfe, Name über Datum, Betrag
    rechts. Maße in .app-drafts.

    Der Bruttobetrag kommt aus grossAmount(), nicht aus der Spalte: ein Entwurf
    hat noch kein gross_total, und die Spalte zu lesen druckte überall 0,00 €.
--}}
@php
    use App\Money\Euro;
@endphp

<div class="app-drafts">
    @forelse ($figures->drafts as $draft)
        <a href="{{ \App\Filament\Resources\Invoices\InvoiceResource::getUrl('view', ['record' => $draft]) }}" class="app-drafts-row">
            <span class="app-drafts-angaben">
                <span class="app-drafts-name">{{ $draft->customer?->name }}</span>
                <span class="app-drafts-date">{{ $draft->issued_on->format('d.m.Y') }}</span>
            </span>
            <span class="app-drafts-amount">{{ Euro::format($draft->grossAmount()) }}</span>
        </a>
    @empty
        <p class="app-drafts-empty">{{ __('dashboard.drafts.empty') }}</p>
    @endforelse

    @if ($figures->drafts->isNotEmpty())
        <a href="{{ $invoicesUrl(\App\Enums\DocumentStatus::Draft) }}" class="app-cardlink">
            {{ __('dashboard.drafts.all') }}
            <x-filament::icon icon="heroicon-m-arrow-right" class="app-cardlink-icon" />
        </a>
    @endif
</div>
