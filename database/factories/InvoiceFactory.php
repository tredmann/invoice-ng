<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Documents\FrozenBlock;
use App\Enums\DocumentStatus;
use App\Enums\PaymentTerm;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * `number` is deliberately absent: it is drawn at issue and never chosen,
     * and a factory that set one would hide that.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'status' => DocumentStatus::Draft,
            'issued_on' => today(),
            'performed_on' => today(),
            'payment_term' => PaymentTerm::Net14,
        ];
    }

    /**
     * The customer is created against the document's own company rather than
     * in the definition, because the company may come from `for()` and is only
     * resolved by the time the model is made. A customer of another company
     * would make every tenancy test pass for the wrong reason.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Invoice $invoice): void {
            if ($invoice->customer_id !== null) {
                return;
            }

            $invoice->customer_id = Customer::factory()
                ->create(['company_id' => $invoice->company_id])
                ->getKey();
        });
    }

    /**
     * An issued Beleg, assembled rather than issued.
     *
     * It exists so the immutability guards and the screens that show an issued
     * Rechnung can be tested without running the real Ausstellvorgang for each
     * of them. **It is not a shortcut for issuing**: it draws no Belegnummer
     * from the Nummernkreis, freezes nothing from live master data and writes no
     * PDF. Anything asserting what issuing *does* has to call
     * `App\Actions\IssueDocument`.
     *
     * The number is a sequence, not a constant: two issued Belege of one company
     * collide on `unique(company_id, number)` otherwise, and the collision looks
     * like a bug in whatever test happened to create the second one.
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DocumentStatus::Issued,
            'number' => 'RE-'.today()->year.'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'due_on' => today()->addDays(14),
            'net_total' => Money::of('100.00', 'EUR'),
            'tax_total' => Money::of('19.00', 'EUR'),
            'gross_total' => Money::of('119.00', 'EUR'),
            'frozen_block' => $this->frozenBlock(),
            'pdf_path' => 'companies/factory/documents/'.today()->year.'/factory.pdf',
            'pdf_sha256' => hash('sha256', 'factory'),
        ]);
    }

    /**
     * A plausible Festschreibung, built through the DTO so a change to its
     * required fields breaks the factory rather than every test that reads one.
     */
    private function frozenBlock(): FrozenBlock
    {
        return FrozenBlock::fromArray([
            'seller' => [
                'name' => 'Musterbetrieb',
                'legal_form' => 'gmbh',
                'street' => 'Musterstraße 1',
                'postal_code' => '10115',
                'city' => 'Berlin',
                'country' => FrozenBlock::COUNTRY,
                'tax_number' => '29/123/45678',
                'vat_id' => null,
                'register_court' => 'Amtsgericht Charlottenburg',
                'register_number' => 'HRB 123456',
                'managing_directors' => 'Erika Mustermann',
                'bank_name' => 'Musterbank',
                'iban' => 'DE02120300000000202051',
                'bic' => 'BYLADEM1001',
                'vat_scheme' => 'standard',
            ],
            'buyer' => [
                'number' => 'K-0001',
                'name' => 'Bauer & Kollegen GmbH',
                'contact_person' => 'Herr Bauer',
                'vat_id' => 'DE811907980',
                'street' => 'Kundenweg 7',
                'postal_code' => '20095',
                'city' => 'Hamburg',
                'country' => FrozenBlock::COUNTRY,
                'email' => 'rechnung@bauer-kollegen.example',
            ],
        ]);
    }
}
