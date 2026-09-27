<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\CheckReadiness;
use App\Actions\CollectFigures;
use App\Company\Figures;
use App\Company\MonthlyRevenue;
use App\Company\Readiness;
use App\Company\ReadinessItem;
use App\Enums\DocumentStatus;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Widgets\RevenueChart;
use App\Models\Company;
use Brick\Math\RoundingMode;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use LogicException;

/**
 * Die **Übersicht** — zwei Bildschirme, je nachdem, ob die Firma schon
 * ausgestellt hat.
 *
 * Vorher die drei Ersten Schritte, die die Bereitschaftsprüfung treibt: eine
 * leere Seite sagt, was als Nächstes zu tun ist, statt sich zu entschuldigen
 * (.ai/guidelines/ui/core.blade.php). Nachher die Kennzahlen aus §9.
 *
 * Die Umschaltung hängt an „hat je ausgestellt", nicht an „hat Umsatz": eine
 * Firma, deren Rechnungen alle storniert wurden, hat abgerechnet, und sie
 * wieder vor die Ersten Schritte zu setzen läse sich wie ein Datenverlust.
 *
 * Filaments zwei Standard-Widgets bleiben fort. Das einzige Widget hier ist der
 * Umsatzverlauf, und die Seite stellt es selbst hin — `content()` baut das
 * Raster, statt `getWidgetsContentComponent()` zu nehmen, weil die Kacheln und
 * die beiden Karten daneben keine Widgets sind.
 *
 * Die Kennzahlen liegen in einer **privaten** Eigenschaft. Livewire dehydriert
 * öffentliche, und ein `Money` oder ein `Carbon` daran ginge dabei kaputt.
 */
class Dashboard extends BaseDashboard
{
    private ?Figures $figures = null;

    public function getTitle(): string
    {
        return __('dashboard.title');
    }

    public function getSubheading(): string
    {
        // „September 2026" — der Monat, auf den sich die Kacheln beziehen, und
        // aus denselben Kennzahlen gelesen, damit er über Mitternacht nicht mit
        // ihnen streitet.
        return $this->figures()->month()->translatedFormat('F Y');
    }

    public function content(Schema $schema): Schema
    {
        $figures = $this->figures();

        if (! $figures->hasIssued) {
            return $schema->components($this->firstSteps());
        }

        return $schema->components([
            Html::make($this->view('filament.dashboard.stats', $figures)),

            // Drei Spalten, damit der Verlauf zwei und der Arbeitsvorrat eine
            // bekommt — auf dem Board 692 zu 340 bei 24 Abstand, was genau
            // 2:1 ist.
            Grid::make(3)->schema([
                ...$this->getWidgetsSchemaComponents([RevenueChart::class], [
                    'months' => array_map(
                        fn (MonthlyRevenue $month): string => $month->month->translatedFormat('M'),
                        $figures->series,
                    ),
                    'values' => array_map(
                        // Ganze Euro: die Achse zählt in Tausendern. Gerechnet
                        // wird damit nichts, das ist Darstellung.
                        fn (MonthlyRevenue $month): int => $month->net->getAmount()->toScale(0, RoundingMode::HalfUp)->toInt(),
                        $figures->series,
                    ),
                ]),

                Section::make(__('dashboard.drafts.heading'))
                    ->description(__('dashboard.drafts.description'))
                    ->columnSpan(1)
                    ->extraAttributes(['class' => 'app-card-snug'])
                    ->schema([Html::make($this->view('filament.dashboard.drafts', $figures))]),
            ]),

            Section::make(__('dashboard.overdue.heading'))
                ->extraAttributes(['class' => 'app-card-plain'])
                // Der Verweis steht auf dem Board rechts neben der Überschrift,
                // nicht unter der Tabelle wie bei den Entwürfen.
                ->headerActions([
                    Action::make('allOpenItems')
                        ->label(__('dashboard.overdue.all'))
                        ->icon(Heroicon::OutlinedArrowRight)
                        ->iconPosition('after')
                        ->link()
                        ->url($this->invoicesUrl(DocumentStatus::Issued)),
                ])
                ->schema([Html::make($this->view('filament.dashboard.overdue', $figures))]),
        ]);
    }

    /**
     * Die Kennzahlen, einmal je Anfrage. `content()` und `getSubheading()`
     * fragen beide, und zwei Läufe wären achtzehn Abfragen für eine Antwort.
     */
    private function figures(): Figures
    {
        return $this->figures ??= (new CollectFigures)($this->company());
    }

    /**
     * @param  view-string  $name
     */
    private function view(string $name, Figures $figures): Htmlable
    {
        return view($name, [
            'figures' => $figures,
            'invoicesUrl' => $this->invoicesUrl(...),
        ]);
    }

    /**
     * Die Rechnungsliste, wahlweise auf einen Status gefiltert.
     *
     * Für „Alle offenen Posten" ist das `issued` und nicht ganz dasselbe: offen
     * heißt ausgestellt *oder* versendet, und der Filter der Liste nimmt nur
     * einen Wert. Heute stimmt es trotzdem, weil nichts `sent` schreibt — und
     * wenn etwas es tut, ist „Offene Posten" ohnehin eine eigene Seite (siehe
     * CONTEXT.md).
     */
    private function invoicesUrl(?DocumentStatus $status = null): string
    {
        $url = InvoiceResource::getUrl('index');

        if (! $status instanceof DocumentStatus) {
            return $url;
        }

        return $url.'?'.http_build_query(['tableFilters' => ['status' => ['value' => $status->value]]]);
    }

    /**
     * @return list<Section>
     */
    private function firstSteps(): array
    {
        $readiness = (new CheckReadiness)($this->company());

        return [
            Section::make(__('company.steps.heading'))
                ->description(__('company.steps.description'))
                ->schema([
                    Section::make(__('company.steps.settings'))
                        ->icon($readiness->canIssue() ? Heroicon::CheckCircle : Heroicon::OutlinedExclamationTriangle)
                        ->iconColor($readiness->canIssue() ? 'success' : 'danger')
                        ->compact()
                        ->schema([
                            Text::make($this->settingsLine($readiness))
                                ->color($readiness->canIssue() ? 'gray' : 'danger'),
                            ...$this->warningLine($readiness),
                        ])
                        ->footer([
                            Action::make('completeSettings')
                                ->label(__('company.steps.open'))
                                ->icon(Heroicon::OutlinedArrowRight)
                                ->iconPosition('after')
                                ->link()
                                ->url($this->settingsUrl()),
                        ]),

                    Section::make(__('company.steps.customer'))
                        ->icon($this->hasCustomer() ? Heroicon::CheckCircle : Heroicon::OutlinedUsers)
                        ->iconColor($this->hasCustomer() ? 'success' : 'gray')
                        ->compact()
                        ->schema([
                            Text::make($this->hasCustomer()
                                ? __('company.steps.customer_done')
                                : __('company.steps.customer_help'))->color('gray'),
                        ])
                        ->footer([
                            Action::make('createCustomer')
                                ->label(__('company.steps.open'))
                                ->icon(Heroicon::OutlinedArrowRight)
                                ->iconPosition('after')
                                ->link()
                                ->url(CustomerResource::getUrl('create')),
                        ]),

                    Section::make(__('company.steps.invoice'))
                        ->icon(Heroicon::OutlinedDocumentText)
                        ->iconColor('gray')
                        ->compact()
                        ->schema([
                            Text::make(__('company.steps.invoice_help'))->color('gray'),
                        ])
                        ->footer([
                            Action::make('createInvoice')
                                ->label(__('company.steps.open'))
                                ->icon(Heroicon::OutlinedArrowRight)
                                ->iconPosition('after')
                                ->link()
                                ->url(InvoiceResource::getUrl('create')),
                        ]),
                ]),
        ];
    }

    private function settingsLine(Readiness $readiness): string
    {
        if ($readiness->canIssue()) {
            return (string) __('company.steps.settings_done');
        }

        return (string) __('company.steps.settings_blocked', [
            'items' => $this->names($readiness->blockers()),
        ]);
    }

    /**
     * A second line only when there is something to say. An always-present
     * "nothing to warn about" line would be noise on the screen that matters
     * least — the one where everything is already in order.
     *
     * @return list<Text>
     */
    private function warningLine(Readiness $readiness): array
    {
        if ($readiness->warnings() === []) {
            return [];
        }

        return [
            Text::make(__('company.steps.settings_warning', [
                'items' => $this->names($readiness->warnings()),
            ]))->color('gray'),
        ];
    }

    /**
     * @param  list<ReadinessItem>  $items
     */
    private function names(array $items): string
    {
        return implode(', ', array_map(
            fn (ReadinessItem $item): string => $item->label(),
            $items,
        ));
    }

    private function hasCustomer(): bool
    {
        return $this->company()->customers()->exists();
    }

    private function settingsUrl(): string
    {
        return route(CompanySettings::getRouteName(), ['tenant' => $this->company()]);
    }

    private function company(): Company
    {
        $company = Filament::getTenant();

        throw_unless($company instanceof Company, LogicException::class, 'The dashboard is only reachable inside a company.');

        return $company;
    }
}
