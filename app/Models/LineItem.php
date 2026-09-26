<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\Unit;
use Brick\Money\Money;
use Database\Factories\LineItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A **Position**: one line of a Beleg.
 *
 * It references no master data (system design §3.6). `unit` holds the UN/ECE
 * code and `tax_rate` the Steuersatz in basis points, both copied from the
 * pickers at the moment of typing. A foreign key would let a rate renamed or
 * deactivated next year reach backwards into a document that §4 makes
 * immutable.
 *
 * `unit_price` is declared because Larastan types it from the migration as an
 * int; the MoneyCast in casts() makes it a Money, the same way Company has to
 * declare its enum-cast columns.
 *
 * @property Unit $unit
 * @property Money $unit_price
 * @property int $tax_rate
 */
#[Fillable([
    'position',
    'title',
    'description',
    'quantity',
    'unit',
    'unit_price',
    'tax_rate',
])]
class LineItem extends Model
{
    /** @use HasFactory<LineItemFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => 'decimal:3',
            'unit' => Unit::class,
            'unit_price' => MoneyCast::class,
            'tax_rate' => 'integer',
        ];
    }
}
