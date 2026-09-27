<?php

declare(strict_types=1);

namespace App\Documents;

use App\Enums\LegalForm;
use App\Enums\VatScheme;
use App\Models\Company;

/**
 * Who we were when the Beleg was issued.
 *
 * Half of the Festschreibung. Every field is a copy rather than a reference:
 * §35a GmbHG and §14 UStG decide what has to print, and what printed must not
 * change when the company later moves, renames itself or switches banks. The
 * PDF already carries these values as pixels; this carries them as data, so the
 * XML and any later screen agree with the page.
 *
 * Nullable where the law allows it to be absent — a company states a
 * Steuernummer *or* a USt-IdNr., and only a registered form has a court. What
 * may not be absent is refused before the transaction opens, by CheckReadiness.
 */
final readonly class SellerIdentity
{
    public function __construct(
        public string $name,
        public LegalForm $legalForm,
        public string $street,
        public string $postalCode,
        public string $city,
        public string $country,
        public ?string $taxNumber,
        public ?string $vatId,
        public ?string $registerCourt,
        public ?string $registerNumber,
        public ?string $managingDirectors,
        public ?string $bankName,
        public ?string $iban,
        public ?string $bic,
        public VatScheme $vatScheme,
    ) {}

    public static function of(Company $company): self
    {
        return new self(
            name: (string) $company->name,
            legalForm: $company->legal_form,
            street: (string) $company->street,
            postalCode: (string) $company->postal_code,
            city: (string) $company->city,
            // Hardcoded until the system can tax a foreign supply at all.
            // EN16931 makes BT-40 mandatory, so the field cannot simply be
            // omitted; offering a country picker while reverse charge and §13b
            // are unbuilt would let a user address an invoice this application
            // would then tax wrongly.
            country: FrozenBlock::COUNTRY,
            taxNumber: self::trimmed($company->tax_number),
            vatId: self::trimmed($company->vat_id),
            registerCourt: self::trimmed($company->register_court),
            registerNumber: self::trimmed($company->register_number),
            managingDirectors: self::trimmed($company->managing_directors),
            bankName: self::trimmed($company->bank_name),
            iban: self::trimmed($company->iban),
            bic: self::trimmed($company->bic),
            vatScheme: $company->vat_scheme,
        );
    }

    /**
     * The name as it prints, and as EN16931's BT-27 wants it.
     *
     * A registered form is part of the legal name and has to appear (§35a
     * GmbHG); an Einzelunternehmen has no designation to append. The
     * str_ends_with check is not defensive — the settings form asks for the
     * name and the form separately, and „Acme GmbH" typed into the name field
     * is the likeliest way this gets filled in.
     */
    public function legalName(): string
    {
        if (! $this->legalForm->isRegistered()) {
            return $this->name;
        }

        $designation = $this->legalForm->getLabel();

        if (str_ends_with($this->name, $designation)) {
            return $this->name;
        }

        return $this->name.' '.$designation;
    }

    /**
     * The Steuernummer or the USt-IdNr., whichever the company states. Both
     * print when both are known.
     */
    public function taxIdentifier(): ?string
    {
        return $this->vatId ?? $this->taxNumber;
    }

    public function isSmallBusiness(): bool
    {
        return $this->vatScheme->isSmallBusiness();
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'legal_form' => $this->legalForm->value,
            'street' => $this->street,
            'postal_code' => $this->postalCode,
            'city' => $this->city,
            'country' => $this->country,
            'tax_number' => $this->taxNumber,
            'vat_id' => $this->vatId,
            'register_court' => $this->registerCourt,
            'register_number' => $this->registerNumber,
            'managing_directors' => $this->managingDirectors,
            'bank_name' => $this->bankName,
            'iban' => $this->iban,
            'bic' => $this->bic,
            'vat_scheme' => $this->vatScheme->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: FrozenBlock::string($data, 'name'),
            legalForm: LegalForm::from(FrozenBlock::string($data, 'legal_form')),
            street: FrozenBlock::string($data, 'street'),
            postalCode: FrozenBlock::string($data, 'postal_code'),
            city: FrozenBlock::string($data, 'city'),
            country: FrozenBlock::string($data, 'country'),
            taxNumber: FrozenBlock::nullableString($data, 'tax_number'),
            vatId: FrozenBlock::nullableString($data, 'vat_id'),
            registerCourt: FrozenBlock::nullableString($data, 'register_court'),
            registerNumber: FrozenBlock::nullableString($data, 'register_number'),
            managingDirectors: FrozenBlock::nullableString($data, 'managing_directors'),
            bankName: FrozenBlock::nullableString($data, 'bank_name'),
            iban: FrozenBlock::nullableString($data, 'iban'),
            bic: FrozenBlock::nullableString($data, 'bic'),
            vatScheme: VatScheme::from(FrozenBlock::string($data, 'vat_scheme')),
        );
    }

    private static function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
