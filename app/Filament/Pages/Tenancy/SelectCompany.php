<?php

declare(strict_types=1);

namespace App\Filament\Pages\Tenancy;

use App\Models\Company;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

/**
 * The page at /admin: one tile per company the user can switch to.
 *
 * It sits outside any company but in the full panel layout, so it looks like
 * every other screen (company-picker spec §1). Filament would otherwise
 * redirect /admin into the default company, or into registration when there is
 * none; AppServiceProvider binds Filament's RedirectToTenantController to this
 * page instead, so it is served on Filament's own route. It never redirects.
 *
 * Not discovered: discovery would also register it under every company.
 */
class SelectCompany extends Page
{
    #[\Override]
    protected static bool $isDiscovered = false;

    public function getTitle(): string
    {
        return __('company.picker.title');
    }

    /**
     * The same list as the switcher, so the two cannot disagree — archived
     * companies stay out of both.
     *
     * @return Collection<int, Company>
     */
    public static function getCompanies(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->getTenants(Filament::getDefaultPanel());
    }

    public function content(Schema $schema): Schema
    {
        $companies = static::getCompanies();

        $main = $companies->isEmpty()
            ? EmptyState::make(__('company.picker.empty'))
                ->icon(Heroicon::OutlinedBuildingOffice2)
                ->footer([$this->createCompanyAction()])
            : Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                ->schema($companies->map(fn (Company $company): Section => $this->companyTile($company))->all());

        $archived = static::getArchivedCompanies();

        return $schema->components([
            $main,
            ...($archived->isEmpty() ? [] : [
                Section::make(__('company.picker.archived', ['count' => $archived->count()]))
                    ->collapsible()
                    ->collapsed()
                    ->schema($archived->map(fn (Company $company): Flex => Flex::make([
                        Text::make($company->name),
                        Actions::make([
                            Action::make('unarchive')
                                ->label(__('company.actions.unarchive'))
                                ->icon(Heroicon::OutlinedArrowUturnLeft)
                                ->link()
                                ->action(fn () => $company->unarchive()),
                        ])->alignEnd(),
                    ])->key("archived-company-{$company->getKey()}"))->all()),
            ]),
        ]);
    }

    /**
     * A tile: the name links into the company, the legal form sits beneath,
     * and the ⋮ menu carries the archive action. The name — not the whole card
     * — is the link, because a button inside a link is invalid HTML.
     */
    private function companyTile(Company $company): Section
    {
        $url = Filament::getDefaultPanel()->getUrl($company);

        return Section::make(new HtmlString(sprintf(
            '<a href="%s" style="overflow-wrap: anywhere">%s</a>',
            e($url),
            e($company->name),
        )))
            ->description($company->legal_form->getLabel())
            ->headerActions([
                ActionGroup::make([
                    Action::make('archive')
                        ->label(__('company.actions.archive'))
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->requiresConfirmation()
                        ->action(fn () => $company->archive()),
                ]),
            ])
            ->key("company-{$company->getKey()}");
    }

    /**
     * The user's archived companies, for the Deaktiviert section. Through the
     * join table, like every other boundary here.
     *
     * @return Collection<int, Company>
     */
    public static function getArchivedCompanies(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->companies()->whereNotNull('archived_at')->orderBy('name')->get();
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // With no company the empty state carries this action; showing it in
        // the header as well would put the same button on the page twice.
        return static::getCompanies()->isEmpty() ? [] : [$this->createCompanyAction()];
    }

    private function createCompanyAction(): Action
    {
        return Action::make('createCompany')
            ->label(__('company.actions.create'))
            ->icon(Heroicon::OutlinedPlus)
            ->url(Filament::getTenantRegistrationUrl());
    }
}
