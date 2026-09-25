<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum LegalForm: string implements HasLabel
{
    case GmbH = 'gmbh';
    case UG = 'ug';
    case SoleProprietorship = 'sole_proprietorship';

    /**
     * Whether this form is entered in the Handelsregister.
     *
     * This is the one question both the settings form and, later, the invoice
     * footer ask of a legal form: a registered company must state its court,
     * its HRB number and its Geschäftsführer, and a sole proprietorship has
     * none of the three. Asking it here keeps the answer in one place instead
     * of a match expression at every call site.
     */
    public function isRegistered(): bool
    {
        return match ($this) {
            self::GmbH, self::UG => true,
            self::SoleProprietorship => false,
        };
    }

    public function getLabel(): string
    {
        return __("company.legal_form.{$this->value}");
    }
}
