<?php

declare(strict_types=1);

namespace App\Company;

/**
 * Whether a missing prerequisite stops an Ausstellvorgang or merely deserves
 * saying out loud.
 *
 * The line is the law, not taste. §14 UStG decides what a Rechnung must carry
 * and §35a GmbHG what a registered company must state; everything else is
 * inconvenience. A Rechnung without an IBAN is valid and awkward to pay, and
 * one without a logo is valid and plain — neither is a reason to refuse to
 * invoice a customer.
 */
enum Severity
{
    case Blocking;
    case Warning;
}
