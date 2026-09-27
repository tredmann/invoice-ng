<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a USt-IdNr against the rule EN16931 actually enforces, plus the
 * German check digit.
 *
 * **BR-CO-09 is the reason this exists.** „The Seller VAT identifier (BT-31),
 * the Seller tax representative VAT identifier (BT-63) and the Buyer VAT
 * identifier (BT-48) shall have a prefix in accordance with ISO code ISO
 * 3166-1 alpha-2 by which the country of issue may be identified." A value
 * without that prefix produces a ZUGFeRD file the recipient's software
 * rejects — and because the identity block is frozen at issue, it is then
 * rejected forever. This was found the hard way: an invoice was issued with
 * `iuoiuoi` in the field and nothing anywhere objected.
 *
 * The prefix list is the EU member states plus the two exceptions the standard
 * names: **EL** for Greece, which does not use its own ISO code GR, and **XI**
 * for Northern Ireland under the Windsor Framework. A list rather than „any two
 * letters", because any two letters is exactly what got through before.
 *
 * Beyond the prefix, only the German number is checked digit by digit. The
 * other 26 national algorithms are deliberately not encoded: a table of
 * foreign checksums goes stale silently, and this application taxes German
 * supplies — see `FrozenBlock::COUNTRY`. Format and length still apply to all.
 */
class VatId implements ValidationRule
{
    /**
     * ISO 3166-1 alpha-2 codes of the EU member states, with the two
     * substitutions EN16931 and the VIES register use in their place.
     *
     * @var list<string>
     */
    private const array PREFIXES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR',
        'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO',
        'SE', 'SI', 'SK', 'XI',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A null USt-IdNr is an absent one: a company may state a Steuernummer
        // instead (§14 UStG), and a Privatkunde never has one. Laravel only
        // skips a rule for a string that trims to empty, so null reaches here
        // and has to be let through explicitly.
        if ($value === null) {
            return;
        }

        if (! is_string($value) || ! self::isValid($value)) {
            $fail('company.errors.vat_id')->translate();
        }
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $vatId = self::normalize($value);

        if (preg_match('/^([A-Z]{2})([0-9A-Z]{2,12})$/', $vatId, $matches) !== 1) {
            return false;
        }

        [, $prefix, $digits] = $matches;

        if (! in_array($prefix, self::PREFIXES, true)) {
            return false;
        }

        if ($prefix !== 'DE') {
            return true;
        }

        return preg_match('/^[0-9]{9}$/', $digits) === 1
            && self::germanCheckDigit($digits) === (int) $digits[8];
    }

    /**
     * Strips whitespace and punctuation and upper-cases, so the same number
     * reads the same however it was typed or pasted off a letterhead.
     *
     * Shared with the mutators on `Company` and `Customer`, which store by the
     * same rule this validates against — one place means the two cannot drift.
     * The `/u` modifier matters for the same reason it does on an IBAN: without
     * it `\s` does not match the non-breaking space a copied value carries.
     */
    public static function normalize(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/[\s.\-\/]+/u', '', $value));
    }

    /**
     * A complete German USt-IdNr from its first eight digits, check digit and
     * all.
     *
     * Public because the factories need valid numbers and computing the check
     * digit a second time in `database/` would be the same algorithm written
     * twice — the arrangement where one copy is fixed and the other is not.
     */
    public static function germanFor(string $eightDigits): string
    {
        $eightDigits = str_pad(substr($eightDigits, 0, 8), 8, '0', STR_PAD_LEFT);

        return 'DE'.$eightDigits.self::germanCheckDigit($eightDigits.'0');
    }

    /**
     * The check digit of a German USt-IdNr, by the procedure the
     * Bundeszentralamt für Steuern publishes (ISO 7064 MOD 11,10).
     *
     * Worth the twenty lines for the same reason the IBAN's mod-97 is: the
     * realistic error is a transposed pair of digits copied off a letterhead,
     * and a prefix-and-length check waves every one of those through.
     */
    private static function germanCheckDigit(string $digits): int
    {
        $product = 10;

        foreach (str_split(substr($digits, 0, 8)) as $digit) {
            $sum = ((int) $digit + $product) % 10;

            if ($sum === 0) {
                $sum = 10;
            }

            $product = (2 * $sum) % 11;
        }

        $check = 11 - $product;

        return $check === 10 ? 0 : $check;
    }
}
