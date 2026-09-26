<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Actions\CheckReadiness;
use App\Company\Readiness;
use App\Company\ReadinessItem;
use App\Filament\Pages\Tenancy\CompanySettings;
use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use LogicException;

/**
 * The company dashboard, until invoicing gives it figures.
 *
 * Filament's two default widgets are gone. An empty screen says what to do next
 * (.ai/guidelines/ui/core.blade.php), and the three things worth doing are the
 * three the mockup lists.
 *
 * Step one is the only consumer of the Bereitschaftsprüfung in this wave. It is
 * where a company whose identity block is incomplete finds out — a hazard
 * CLAUDE.md records and which nothing could previously detect. Refusing to
 * issue from such a company is the invoicing wave's job; this is the wave that
 * makes the condition visible.
 */
class Dashboard extends BaseDashboard
{
    public function content(Schema $schema): Schema
    {
        $readiness = (new CheckReadiness)($this->company());

        return $schema->components([
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
                                ->disabled()
                                ->tooltip(__('company.steps.invoice_disabled')),
                        ]),
                ]),
        ]);
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
