{{--
    Die Kennzahlenreihe (§9): vier Kacheln, gemessen vom Board.

    Eigenes Markup und keine vier Sections: eine Section setzt eine
    Überschrift in Textfarbe über einen Inhaltsblock mit eigenem Innenabstand,
    die Kachel eine stille Beschriftung über eine große Zahl — die umgekehrte
    Betonung, und zwischen beidem läge Filaments 40px statt der gezeichneten 8.
    Die Maße stehen in .app-stats in panel-styles.blade.php.

    **Netto und brutto stehen hier nebeneinander.** Umsatz ist netto, die
    Forderungen sind brutto; die Unterzeilen sagen es, sonst addiert man sie.
--}}
@php
    use App\Money\Euro;

    $change = $figures->monthChange();
@endphp

<div class="app-stats">
    <div class="app-stats-tile">
        <p class="app-stats-label">
            {{ __('dashboard.stats.revenue_month', ['month' => $figures->month()->translatedFormat('F')]) }}
        </p>
        <p class="app-stats-value">{{ Euro::format($figures->monthRevenue) }}</p>

        @if ($change === null)
            {{-- Ohne Vormonat gibt es keinen Prozentsatz, den jemand nachrechnen
                 könnte — dann sagt die Zeile lieber, worauf sich die Zahl bezieht. --}}
            <p class="app-stats-note">{{ __('dashboard.stats.revenue_month_note') }}</p>
        @else
            <p @class(['app-stats-note', 'app-stats-up' => $change >= 0, 'app-stats-down' => $change < 0])>
                <x-filament::icon
                    :icon="$change >= 0 ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down'"
                    class="app-stats-arrow"
                />
                {{ __('dashboard.stats.change', [
                    'percent' => ($change < 0 ? '−' : '').abs($change),
                    'month' => $figures->previousMonth()->translatedFormat('F'),
                ]) }}
            </p>
        @endif
    </div>

    <div class="app-stats-tile">
        <p class="app-stats-label">{{ __('dashboard.stats.revenue_year', ['year' => $figures->year()]) }}</p>
        <p class="app-stats-value">{{ Euro::format($figures->yearRevenue) }}</p>
        <p class="app-stats-note">{{ __('dashboard.stats.revenue_year_note', ['year' => $figures->year()]) }}</p>
    </div>

    <div class="app-stats-tile">
        <p class="app-stats-label">{{ __('dashboard.stats.open') }}</p>
        <p class="app-stats-value">{{ Euro::format($figures->openTotal) }}</p>
        <p class="app-stats-note">{{ trans_choice('dashboard.stats.invoice_count', $figures->openCount) }}</p>
    </div>

    {{-- Rot nur, wenn es etwas zu warnen gibt: bei 0,00 € würde die Kachel vor
         nichts warnen, und eine Seite, auf der immer etwas rot ist, warnt nie. --}}
    <div @class(['app-stats-tile', 'app-stats-danger' => $figures->overdueCount > 0])>
        <p class="app-stats-label">
            @if ($figures->overdueCount > 0)
                <x-filament::icon icon="heroicon-m-exclamation-triangle" class="app-stats-arrow" />
            @endif
            {{ __('dashboard.stats.overdue') }}
        </p>
        <p class="app-stats-value">{{ Euro::format($figures->overdueTotal) }}</p>
        <p class="app-stats-note">{{ trans_choice('dashboard.stats.invoice_count', $figures->overdueCount) }}</p>
    </div>
</div>
