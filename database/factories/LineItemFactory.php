<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Unit;
use App\Models\Invoice;
use App\Models\LineItem;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\Sequence;

/**
 * @extends Factory<LineItem>
 */
class LineItemFactory extends Factory
{
    protected $model = LineItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Invoice::factory(),
            'title' => 'Konzeption Netzwerkplanung',
            'description' => null,
            'quantity' => '1',
            'unit' => Unit::Hour,
            'unit_price' => Money::of('95.00', 'EUR'),
            'tax_rate' => 1900,
        ];
    }

    /**
     * Positions count from one across a batch, so `count(3)` does not put
     * three Positionen at position 1 and collide on
     * `unique(document_id, position)`. An explicit position still wins: an
     * attribute passed to create() is applied after this state.
     */
    public function configure(): static
    {
        return $this->sequence(fn (Sequence $sequence): array => [
            'position' => $sequence->index + 1,
        ]);
    }
}
