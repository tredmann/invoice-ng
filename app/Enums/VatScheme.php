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

    public function getLabel(): string
    {
        return __("company.vat_scheme.{$this->value}");
    }
}
