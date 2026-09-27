<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\Customer;

/**
 * The Rechnungsanschrift of the Kunde as it read when the Beleg was issued.
 *
 * The other half of the Festschreibung. A customer who moves next year does not
 * change where last year's invoice was addressed, and a customer who is
 * deactivated keeps standing on every Beleg that names them.
 *
 * The Kundennummer is frozen as the formatted string it prints as, not as the
 * integer: that string is what the customer quotes back.
 */
final readonly class BuyerAddress
{
    public function __construct(
        public string $number,
        public string $name,
        public ?string $contactPerson,
        public ?string $vatId,
        public string $street,
        public string $postalCode,
        public string $city,
        public string $country,
        public ?string $email,
    ) {}

    public static function of(Customer $customer): self
    {
        return new self(
            number: $customer->formattedNumber(),
            name: (string) $customer->name,
            contactPerson: self::trimmed($customer->contact_person),
            vatId: self::trimmed($customer->vat_id),
            street: (string) $customer->street,
            postalCode: (string) $customer->postal_code,
            city: (string) $customer->city,
            // See SellerIdentity::of() for why this is not a column.
            country: FrozenBlock::COUNTRY,
            // Frozen because versenden reads it, and a Beleg has to record the
            // address it was sent to rather than the one the customer has now.
            email: self::trimmed($customer->email),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'name' => $this->name,
            'contact_person' => $this->contactPerson,
            'vat_id' => $this->vatId,
            'street' => $this->street,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'country' => $this->country,
            'email' => $this->email,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            number: FrozenBlock::string($data, 'number'),
            name: FrozenBlock::string($data, 'name'),
            contactPerson: FrozenBlock::nullableString($data, 'contact_person'),
            vatId: FrozenBlock::nullableString($data, 'vat_id'),
            street: FrozenBlock::string($data, 'street'),
            postalCode: FrozenBlock::string($data, 'postal_code'),
            city: FrozenBlock::string($data, 'city'),
            country: FrozenBlock::string($data, 'country'),
            email: FrozenBlock::nullableString($data, 'email'),
        );
    }

    private static function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
