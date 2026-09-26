<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Pages\Page;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The crumbs the top bar shows after the company switcher (top-bar trail
 * spec §2.2): section, then record, then the page where it is not the record
 * itself. The last crumb is the current page and carries no link.
 *
 * Built from Filament's own breadcrumbs, so nested resources and clusters
 * come along for free. Filament ends every resource trail with a word for the
 * page — "Übersicht" on a list, "Ansehen" on a record — that repeats the crumb
 * before it; those are dropped. "Erstellen" gives way to the page title, which
 * says what is being created ("Neuer Kunde").
 */
final class Breadcrumbs
{
    /**
     * @return list<array{label: string, url: string|null}>
     */
    public static function for(Page $page): array
    {
        $crumbs = $page->getBreadcrumbs();

        if ($crumbs === []) {
            return [['label' => self::text($page->getTitle()), 'url' => null]];
        }

        if ($page instanceof ListRecords || $page instanceof ViewRecord) {
            array_pop($crumbs);
        } elseif ($page instanceof CreateRecord) {
            array_pop($crumbs);
            $crumbs[] = self::text($page->getTitle());
        }

        $trail = [];
        $current = array_key_last($crumbs);

        foreach ($crumbs as $url => $label) {
            $trail[] = [
                'label' => self::text($label),
                // The current page is never a link, whatever Filament keyed it by.
                'url' => $url !== $current && is_string($url) ? $url : null,
            ];
        }

        return $trail;
    }

    /**
     * Plain text: Blade escapes the trail when it prints it, so a label must
     * not arrive already escaped.
     */
    private static function text(string|Htmlable $label): string
    {
        return $label instanceof Htmlable
            ? html_entity_decode(strip_tags($label->toHtml()), ENT_QUOTES | ENT_HTML5)
            : $label;
    }
}
