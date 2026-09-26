<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\TaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaxRate>
 */
class TaxRateFactory extends Factory
{
    protected $model = TaxRate::class;

    /**
     * 16 % rather than 19 %: every company is seeded with 19, 7 and 0 on
     * creation, and `unique(company_id, rate)` would refuse a fourth row
     * repeating one of them. 16 % is a rate Germany really used, so the
     * default makes a row that could exist rather than a placeholder.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'rate' => 1600,
            'name' => 'Alter Regelsatz',
            'is_default' => false,
        ];
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'deactivated_at' => now(),
        ]);
    }
}
