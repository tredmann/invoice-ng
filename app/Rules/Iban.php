<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates an IBAN by format and by its mod-97 checksum.
 *
 * The checksum is the point. The realistic error is a transposed pair of
 * digits in an account number typed off a bank statement, and mod-97 catches
 * every one of those. A length-and-prefix check would not.
 *
 * Country-specific lengths are deliberately not encoded: the checksum plus a
 * length range rejects everything worth rejecting without a table that goes
 * stale.
 */
class Iban implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // A null IBAN is an absent one, and bank details are optional until a
        // document needs them (spec §7). Laravel only skips this rule for a
        // string that trims to empty, so null reaches us and has to be let
        // through explicitly — otherwise a blank optional field on the
        // settings form is rejected.
        if ($value === null) {
            return;
        }

        if (! is_string($value)) {
            $fail('company.errors.iban')->translate();

            return;
        }

        $iban = self::normalize($value);

        if (preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban) !== 1) {
            $fail('company.errors.iban')->translate();

            return;
        }

        if ($this->remainderOf($iban) !== 1) {
            $fail('company.errors.iban')->translate();
        }
    }

    /**
     * Strips whitespace and upper-cases, so the same account reads the same
     * however it was typed or pasted.
     *
     * Shared with `Company::iban()`'s mutator, which stores by the same rule
     * this validates against — one place means the two cannot drift apart.
     * The `/u` modifier matters: without it `\s` does not match U+00A0, the
     * non-breaking space an IBAN copied off an online-banking page carries.
     */
    public static function normalize(string $value): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', '', $value));
    }

    /**
     * The mod-97 remainder, computed in chunks.
     *
     * An IBAN rearranged into digits is far wider than an integer, so it is
     * folded seven digits at a time — which keeps this free of bcmath and of
     * any assumption about the container's extensions.
     */
    private function remainderOf(string $iban): int
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);

        $numeric = '';

        foreach (str_split($rearranged) as $character) {
            $numeric .= ctype_alpha($character)
                ? (string) (ord($character[0]) - 55)
                : $character;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int) ($remainder.$chunk) % 97;
        }

        return $remainder;
    }
}
