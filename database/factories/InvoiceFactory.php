<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Enums\PaymentTerm;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
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
     * A state nothing in the application can produce yet. It exists so the
     * immutability guards can be watched refusing something.
     */
    public function issued(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => DocumentStatus::Issued,
            'number' => 1,
        ]);
    }
}
