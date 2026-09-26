<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\FromFormState;
use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CustomerType: string implements HasColor, HasLabel
{
    use FromFormState;

    case Business = 'business';
    case PrivatePerson = 'private_person';

    /**
     * Whether this customer can carry a contact person and a VAT ID.
     *
     * The one question both the form (render the fields?) and the model
     * (clear them on save?) ask. Asking it here keeps the two from disagreeing
     * about which types have them.
     */
    public function isBusiness(): bool
    {
        return match ($this) {
            self::Business => true,
            self::PrivatePerson => false,
        };
    }

    public function getLabel(): string
    {
        return __("customer.type.{$this->value}");
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Business => 'info',
            self::PrivatePerson => 'gray',
        };
    }
}
