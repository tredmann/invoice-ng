<?php

namespace Database\Factories;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * A complete GmbH, so a test that cares about one field does not have to
     * supply the other thirteen. The slug is deliberately absent: it is the
     * model's job, and a factory that set it would hide that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' GmbH',
            'legal_form' => LegalForm::GmbH,
            'vat_scheme' => VatScheme::Standard,
            'street' => fake()->streetAddress(),
            'postal_code' => fake()->numerify('#####'),
            'city' => fake()->city(),
            'tax_number' => fake()->numerify('###/###/#####'),
            'vat_id' => 'DE'.fake()->numerify('#########'),
            'register_court' => 'Amtsgericht '.fake()->city(),
            'register_number' => 'HRB '.fake()->numerify('#####'),
            'managing_directors' => fake()->name(),
            'bank_name' => fake()->company(),
            'iban' => 'DE89370400440532013000',
            'bic' => 'COBADEFFXXX',
        ];
    }

    /**
     * A sole proprietorship: no Handelsregister entry, no Geschäftsführer.
     */
    public function soleProprietorship(): static
    {
        return $this->state(fn (array $attributes): array => [
            'legal_form' => LegalForm::SoleProprietorship,
            'register_court' => null,
            'register_number' => null,
            'managing_directors' => null,
        ]);
    }

    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => [
            'deactivated_at' => now(),
        ]);
    }
}
