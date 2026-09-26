<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Parental\HasParent;

/**
 * A **Rechnung**: a Zahlungsaufforderung to a Kunde for a service rendered.
 *
 * The only Belegart that exists. `Invoice::query()` scopes itself to rows of
 * this type, and `Document::query()` hands each row back as its own class.
 */
class Invoice extends Document
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasParent;
}
