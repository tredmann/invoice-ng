<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;

class CompanySettings extends EditTenantProfile
{
    #[\Override]
    protected static ?string $slug = 'settings';

    public static function getLabel(): string
    {
        return __('company.settings.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }
}
