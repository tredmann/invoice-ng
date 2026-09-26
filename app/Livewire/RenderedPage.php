<?php

declare(strict_types=1);

namespace App\Livewire;

use Filament\Pages\Page;

/**
 * The Filament page this request rendered, for the top bar to read its trail
 * from (top-bar trail spec §3).
 *
 * The top bar is its own Livewire component, mounted while the page's layout
 * renders, and by then the page has left Livewire's render stack —
 * Livewire::current() in the top bar's mount() is the top bar itself (spike
 * findings). So AppServiceProvider records each page as it renders, here, and
 * the top bar takes it at mount. Scoped: one per request, never shared.
 */
final class RenderedPage
{
    public ?Page $page = null;
}
