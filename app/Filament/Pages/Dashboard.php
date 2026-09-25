<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Pages\Tenancy\CompanySettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The company dashboard, until invoicing gives it content.
 *
 * Filament's two default widgets are gone. An empty screen says what to do
 * next (.ai/guidelines/ui/core.blade.php), and today the only useful next step
 * is completing the company data.
 */
class Dashboard extends BaseDashboard
{
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmptyState::make(__('company.dashboard.empty'))
                ->icon(Heroicon::OutlinedInbox)
                ->footer([
                    Action::make('completeSettings')
                        ->label(__('company.dashboard.complete_settings'))
                        ->icon(Heroicon::OutlinedArrowRight)
                        ->iconPosition('after')
                        ->link()
                        ->url(route(CompanySettings::getRouteName(), ['tenant' => Filament::getTenant()])),
                ]),
        ]);
    }
}
