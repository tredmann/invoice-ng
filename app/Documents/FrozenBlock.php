<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\Company;
use App\Models\Customer;
use InvalidArgumentException;

/**
 * The Festschreibung: who we were and where the Kunde was, at the instant the
 * Beleg was issued (system design §4).
 *
 * It is small on purpose. There is no product catalogue, so a Position already
 * carries its own values and needs no snapshot; what came from Stammdaten is
 * only the two parties. The Zahlungsziel, which §4 also names, is already its
 * own frozen column on the document — putting it here too would give one value
 * two homes and a way to disagree with itself.
 *
 * Stored as jsonb through FrozenBlockCast. A readonly DTO and a plain cast
 * rather than a package: tech stack §6.3 rejects spatie/laravel-data for
 * exactly this, and the shape is read far more often than it is written.
 */
final readonly class FrozenBlock
{
    /**
     * EN16931 makes a country code mandatory on both postal addresses (BR-09
     * for the seller, BR-11 for the buyer), and neither companies nor customers
     * carries one. It is frozen as DE rather than asked for, because nothing in
     * this system can tax a supply outside Germany: there is no reverse charge,
     * no §13b, no intra-EU exemption. A country picker would let a user address
     * an invoice that would then be taxed wrongly, which is worse than not
     * offering the choice. The day one of those exists, this becomes a column.
     */
    public const string COUNTRY = 'DE';

    public function __construct(
        public SellerIdentity $seller,
        public BuyerAddress $buyer,
    ) {}

    public static function of(Company $company, Customer $customer): self
    {
        return new self(
            seller: SellerIdentity::of($company),
            buyer: BuyerAddress::of($customer),
        );
    }

    /**
     * @return array{seller: array<string, string|null>, buyer: array<string, string|null>}
     */
    public function toArray(): array
    {
        return [
            'seller' => $this->seller->toArray(),
            'buyer' => $this->buyer->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            seller: SellerIdentity::fromArray(self::section($data, 'seller')),
            buyer: BuyerAddress::fromArray(self::section($data, 'buyer')),
        );
    }

    /**
     * Reading a frozen block that is missing a field is a broken record, not a
     * blank one: it means something wrote the column without going through
     * this class. Refusing loudly is the only way that gets noticed, because a
     * silent null would print an invoice with no seller address on it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function section(array $data, string $key): array
    {
        $section = $data[$key] ?? null;

        throw_unless(
            is_array($section),
            InvalidArgumentException::class,
            sprintf('A Festschreibung is missing its [%s] block.', $key),
        );

        return $section;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        throw_unless(
            is_string($value) && trim($value) !== '',
            InvalidArgumentException::class,
            sprintf('A Festschreibung is missing its [%s].', $key),
        );

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        if ($value === null) {
            return null;
        }

        throw_unless(
            is_string($value),
            InvalidArgumentException::class,
            sprintf('A Festschreibung holds a non-string [%s].', $key),
        );

        return trim($value) === '' ? null : $value;
    }
}
