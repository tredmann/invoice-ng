<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\Unit;
use App\Models\Document;
use App\Models\LineItem;

/**
 * Moves the Positionen between the repeater's state and the database.
 *
 * In a trait because the create and the edit page do the same thing and a
 * second copy is how the two drift apart — the same reason
 * `SeparatesFormActions` exists.
 *
 * The repeater is deliberately not relationship-bound. Its rows carry German
 * decimals and a Steuersatz in basis points, neither of which is what the
 * column holds, so the mapping has to happen somewhere either way.
 */
trait HandlesLineItems
{
    /** @var list<array<string, mixed>> */
    protected array $lineItemState = [];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function liftLineItems(array $data): array
    {
        $rows = $data['line_items'] ?? [];
        $this->lineItemState = array_values(is_array($rows) ? $rows : []);
        unset($data['line_items']);

        return $data;
    }

    /**
     * Rewritten wholesale rather than diffed: reordering two Positionen in
     * place would collide on `unique(document_id, position)` halfway through
     * the update.
     *
     * Affordable only because nothing references a Position and because only a
     * draft can be saved. The wave that issues will have to stop doing this,
     * and `LineItem`'s guard is what will tell it.
     */
    protected function writeLineItems(Document $document): void
    {
        LineItem::query()->where('document_id', $document->getKey())->delete();

        foreach ($this->lineItemState as $index => $row) {
            $document->lineItems()->create([
                'position' => $index + 1,
                'title' => (string) ($row['title'] ?? ''),
                'description' => ($row['description'] ?? '') === '' ? null : (string) $row['description'],
                'quantity' => LineItem::quantityFrom((string) ($row['quantity'] ?? '0')),
                'unit' => Unit::fromFormState($row['unit'] ?? null),
                'unit_price' => LineItem::priceFrom((string) ($row['unit_price'] ?? '0')),
                'tax_rate' => (int) ($row['tax_rate'] ?? 0),
            ]);
        }
    }

    /**
     * The other direction, for the edit form.
     *
     * @return list<array<string, mixed>>
     */
    protected function lineItemsFor(Document $document): array
    {
        // array_values, because PHPStan reads a collection's all() as an array
        // with int keys and the return type promises a list.
        return array_values($document->lineItems
            ->map(fn (LineItem $item): array => [
                'title' => $item->title,
                'description' => $item->description,
                'quantity' => LineItem::formatQuantity((string) $item->quantity),
                'unit' => $item->unit->value,
                'unit_price' => LineItem::formatPrice($item->unit_price),
                'tax_rate' => $item->tax_rate,
            ])
            ->all());
    }
}
