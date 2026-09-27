<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a German Steuernummer by shape and length.
 *
 * **No checksum, deliberately.** Unlike an IBAN or a USt-IdNr, a Steuernummer
 * carries no check digit: it is a Bundesland's own key, written 10 or 11 digits
 * in the Landesformat („12/345/67890") or 13 in the bundeseinheitliches Format,
 * and the Finanzamt prefixes differ per state. Encoding a per-state table would
 * go stale silently and reject legitimate numbers for no gain.
 *
 * So what is checked is what can be checked without guessing: digits and the
 * separators people actually type, and a length that rules out a number too
 * short to be one. That is enough to catch the failure this was written for —
 * `8989898`, seven digits, typed to get past a required field and then printed
 * on an issued invoice under §14 Abs. 4 Nr. 2 UStG.
 *
 * EN16931 imposes nothing here: BT-32 is free text under `schemeID="FC"`, which
 * is why the official Schematron passed that invoice. This rule is about the
 * document being right, not about the XML being accepted.
 */
class TaxNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Absent is allowed: §14 UStG accepts a Steuernummer *or* a USt-IdNr,
        // and which one a company states is its own business. Laravel only
        // skips a rule for a string that trims to empty, so null reaches here.
        if ($value === null) {
            return;
        }

        if (! is_string($value) || ! self::isValid($value)) {
            $fail('company.errors.tax_number')->translate();
        }
    }

    public static function isValid(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $trimmed = trim($value);

        // The separators are part of how a Steuernummer is written, so they
        // are accepted — but only those, and only between digits.
        if (preg_match('#^[0-9][0-9\s./-]*[0-9]$#u', $trimmed) !== 1) {
            return false;
        }

        $digits = (string) preg_replace('/\D+/u', '', $trimmed);

        return strlen($digits) >= 10 && strlen($digits) <= 13;
    }
}
