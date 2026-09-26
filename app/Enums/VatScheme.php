<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Which VAT scheme a company invoices under.
 *
 * `SmallBusiness` is the election under §19 UStG: no VAT is charged and the
 * invoice carries a note saying so. It carries no behaviour in this wave — it
 * is here because it is master data entered now, and because it is the reason
 * a VAT ID cannot be required of every company.
 */
enum VatScheme: string implements HasLabel
{
    case Standard = 'standard';
    case SmallBusiness = 'small_business';

    /**
     * Coerces a form's state — the enum itself, its backing string, or nothing
     * yet — to a scheme. Forms hold whichever of these they were last given, so
     * a closure that asks the state a question has to accept all three. The
     * same shape as `CustomerType::fromFormState()`.
     */
    public static function fromFormState(mixed $state): ?self
    {
        if ($state instanceof self) {
            return $state;
        }

        return is_string($state) ? self::tryFrom($state) : null;
    }

    /**
     * Whether this company issues under §19 UStG: 0 %, no USt block, and the
     * prescribed note. The one question the settings form and
     * `Company::selectableTaxRates()` both ask.
     */
    public function isSmallBusiness(): bool
    {
        return $this === self::SmallBusiness;
    }

    public function getLabel(): string
    {
        return __("company.vat_scheme.{$this->value}");
    }
}
