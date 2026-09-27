{{--
    Die überfälligen Rechnungen, am längsten wartende zuerst — vier Spalten mit
    den Breiten des Boards, kein Filament-Table: die Karte zeigt drei Zeilen
    ohne Sortierung, Suche oder Seitenzahlen, und eine Tabelle brächte all das
    mit. Maße in .app-overdue.

    „seit N Tagen" kommt aus daysOverdue(), damit hier nichts stehen kann, was
    das Abzeichen auf der Rechnung nicht überfällig nennt.
--}}
@php
    use App\Money\Euro;
@endphp

<div class="app-overdue">
    @if ($figures->overdueDocuments->isEmpty())
        <p class="app-overdue-empty">{{ __('dashboard.overdue.empty') }}</p>
    @else
        <div class="app-overdue-scroll">
            <table class="app-overdue-table">
                <thead>
                    <tr>
                        <th class="app-overdue-number">{{ __('dashboard.overdue.number') }}</th>
                        <th class="app-overdue-customer">{{ __('dashboard.overdue.customer') }}</th>
                        <th class="app-overdue-due">{{ __('dashboard.overdue.due') }}</th>
                        <th class="app-overdue-total">{{ __('dashboard.overdue.total') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($figures->overdueDocuments as $document)
                        @php($days = $document->daysOverdue())
                        <tr>
                            <td class="app-overdue-number">
                                <a href="{{ \App\Filament\Resources\Invoices\InvoiceResource::getUrl('view', ['record' => $document]) }}">
                                    {{ $document->number }}
                                </a>
                            </td>
                            <td class="app-overdue-customer">{{ $document->customer?->name }}</td>
                            <td class="app-overdue-due">
                                {{ $document->due_on?->format('d.m.Y') }} ·
                                {{ $days === 1
                                    ? __('dashboard.overdue.since_one')
                                    : __('dashboard.overdue.since', ['days' => $days]) }}
                            </td>
                            <td class="app-overdue-total">{{ Euro::format($document->grossAmount()) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
