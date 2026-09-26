<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * A complete business customer. The number is deliberately absent: it is
     * the model's job, and a factory that set it would hide that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => CustomerType::Business,
            'name' => fake()->unique()->company(),
            'contact_person' => fake()->name(),
            'vat_id' => 'DE'.fake()->numerify('#########'),
            'street' => fake()->streetAddress(),
            'postal_code' => fake()->numerify('#####'),
            'city' => fake()->city(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    public function privatePerson(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CustomerType::PrivatePerson,
            'name' => fake()->name(),
            'contact_person' => null,
            'vat_id' => null,
        ]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'archived_at' => now(),
        ]);
    }
}
