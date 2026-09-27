<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * The EN16931 XML of a Beleg did not pass validation.
 *
 * Thrown inside the issue transaction, which is the whole point (§7): a
 * document that would not pass the recipient's software never becomes an issued
 * invoice, and the Belegnummer it would have consumed is rolled back with it.
 */
final class ZugferdValidationFailed extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(sprintf(
            'The EN16931 XML of this Beleg is invalid: %s',
            implode('; ', array_slice($errors, 0, 5)),
        ));
    }
}
