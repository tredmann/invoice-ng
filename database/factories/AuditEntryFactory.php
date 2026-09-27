<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditEvent;
use App\Models\AuditEntry;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEntry>
 */
class AuditEntryFactory extends Factory
{
    protected $model = AuditEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'document_id' => Invoice::factory()->issued(),
            'event' => AuditEvent::Issued,
            'occurred_at' => now(),
            'actor_id' => null,
            'details' => null,
        ];
    }
}
