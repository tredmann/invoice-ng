<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Company;
use App\Models\NumberRange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NumberRange>
 */
class NumberRangeFactory extends Factory
{
    protected $model = NumberRange::class;

    /**
     * `last_reset_year` is deliberately absent: null means nothing has been
     * drawn yet, which is the state a freshly configured range is in, and a
     * factory that stamped a year would hide the first-draw case.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'prefix' => 'RE-',
            'padding' => 4,
            'next_value' => 1,
            'include_year' => true,
            'reset_yearly' => true,
        ];
    }
}
