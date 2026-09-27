<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

/**
 * Der **Umsatzverlauf**: zwölf Monate netto, der laufende in Akzentfarbe.
 *
 * Bekommt seine Zahlen als einfache Arrays beim Mounten, statt sie selbst zu
 * holen. Zwei Gründe: die Übersicht hat sie ohnehin schon (ein zweiter
 * `CollectFigures`-Lauf wären neun Abfragen für dieselbe Antwort), und Livewire
 * dehydriert öffentliche Eigenschaften — ein `Money` oder ein `Carbon` daran
 * ginge dabei kaputt. Was hier ankommt, sind Zahlen und Strings.
 *
 * Wird nur dort gerendert, wo die Übersicht sie hinstellt. Der Ordner wird zwar
 * von `discoverWidgets()` gefunden, aber `Filament::getWidgets()` liest allein
 * die Dashboard-Seite aus, und die baut ihr `content()` selbst.
 */
class RevenueChart extends ChartWidget
{
    /**
     * Die Monatskürzel der Achse, ältester zuerst.
     *
     * @var list<string>
     */
    public array $months = [];

    /**
     * Der Nettoumsatz je Monat, in ganzen Euro — die Achse zählt in Tausendern,
     * und Cent haben auf einer Säule nichts zu suchen. Gerechnet wird mit
     * ihnen nirgends: das hier ist Darstellung.
     *
     * @var list<int>
     */
    public array $values = [];

    /**
     * Nicht nachgeladen.
     *
     * Filament lädt Widgets standardmäßig faul, weil eines meist selbst
     * abfragt, was es zeigt. Dieses bekommt seine zwölf Zahlen schon beim
     * Mounten mit — Nachladen wäre eine zweite Runde für eine Antwort, die
     * bereits vorliegt, und der Verlauf blitzte beim Öffnen der Seite nach.
     */
    #[\Override]
    protected static bool $isLazy = false;

    /**
     * Zwei von drei Spalten des Rasters, das die Übersicht aufspannt — auf dem
     * Board 692 zu 340 neben dem Arbeitsvorrat.
     *
     * @var int|string|array<string, int|null>
     */
    #[\Override]
    protected int|string|array $columnSpan = 2;

    #[\Override]
    protected ?string $maxHeight = '216px';

    /**
     * Zwei Farben von Hand, und nur hier.
     *
     * Filament reicht seine Farben sonst über leere Elemente in den Canvas
     * (`.fi-wi-chart-bg-color`), damit ein Stylesheet sie setzen kann — aber
     * das gilt für alle Säulen zugleich. Eine einzelne Säule hervorzuheben
     * verlangt ein Array auf dem Datensatz, und ein Canvas löst `var(…)` nicht
     * auf. Beide Werte stehen so auf dem Board: `--gray-400`-Grau und das
     * Bernstein der Panelfarbe.
     */
    private const string BAR = '#848d9c';

    private const string BAR_CURRENT = '#d97706';

    public function getHeading(): string
    {
        return __('dashboard.chart.heading');
    }

    public function getDescription(): string
    {
        return __('dashboard.chart.description');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $last = count($this->values) - 1;

        return [
            'datasets' => [[
                'data' => $this->values,
                'backgroundColor' => array_map(
                    fn (int $index): string => $index === $last ? self::BAR_CURRENT : self::BAR,
                    array_keys($this->values),
                ),
                // Die Komponente zwingt den Säulen sonst einen Rand in ihrer
                // CSS-Farbe auf; das Board zeichnet keinen.
                'borderWidth' => 0,
                'borderRadius' => 4,
                'barThickness' => 20,
            ]],
            'labels' => $this->months,
        ];
    }

    protected function getOptions(): RawJs
    {
        // RawJs und kein Array, weil die Achse deutsch beschriftet ist
        // („15.000") und der laufende Monat fett steht — beides sind
        // Rückrufe, die durch ein JSON-Array nicht gehen.
        return RawJs::make(<<<'JS'
            {
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            label: (item) => item.parsed.y.toLocaleString('de-DE') + ' €',
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        ticks: {
                            maxTicksLimit: 5,
                            font: { size: 11 },
                            callback: (value) => value.toLocaleString('de-DE'),
                        },
                    },
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: (ctx) => ({
                                size: 11,
                                weight: ctx.index === ctx.chart.data.labels.length - 1 ? 600 : 400,
                            }),
                        },
                    },
                },
            }
        JS);
    }
}
