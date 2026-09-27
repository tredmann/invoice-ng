<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Company\Readiness;
use App\Company\ReadinessItem;
use DomainException;

/**
 * The company cannot issue a Beleg yet, and these are the things missing.
 *
 * System design §8.1 requires the prerequisites to be „reported as a list, not
 * raised as an exception halfway through issuing", so the Readiness travels with
 * the exception: the dialogue in front of the action names each blocker rather
 * than showing whatever a generic message happened to say.
 *
 * Thrown **before** the transaction opens. Nothing is locked, no Belegnummer is
 * drawn and nothing is rolled back — refusing early is the difference between
 * telling the owner what to fix and telling them something went wrong.
 */
final class CompanyNotReady extends DomainException
{
    public function __construct(public readonly Readiness $readiness)
    {
        parent::__construct(sprintf(
            'This company cannot issue a Beleg yet: %s.',
            implode(', ', array_map(
                fn (ReadinessItem $item): string => $item->key,
                $readiness->blockers(),
            )),
        ));
    }
}
