<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Filament\Breadcrumbs;
use Filament\Facades\Filament;
use Filament\Livewire\Topbar as BaseTopbar;
use Livewire\Attributes\Locked;

/**
 * Filament's top bar, holding the page's trail (top-bar trail spec §3).
 *
 * The top bar knows nothing of the page. It takes the trail once, at mount,
 * from the page this request rendered (RenderedPage), and keeps it as a
 * property: on the top bar's own re-renders (`refresh-topbar`) no page
 * renders, so there would be nothing to ask.
 *
 * Locked: the trail is rendered as links, and a client that could rewrite it
 * could point them anywhere.
 */
class Topbar extends BaseTopbar
{
    /** @var list<array{label: string, url: string|null}> */
    #[Locked]
    public array $trail = [];

    public function mount(): void
    {
        $page = resolve(RenderedPage::class)->page;

        // Outside a company — the picker on /admin — there is no trail.
        $this->trail = $page !== null && Filament::getTenant() !== null
            ? Breadcrumbs::for($page)
            : [];
    }
}
