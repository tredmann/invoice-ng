<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Company;
use RuntimeException;

class CannotArchiveLastCompany extends RuntimeException
{
    public function __construct(public readonly Company $company)
    {
        parent::__construct(
            "Refusing to archive [{$company->name}]: it is the last active company."
        );
    }
}
