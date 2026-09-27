<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What an append-only Verlauf entry records (system design §3.8).
 *
 * One case, because one thing in this system performs auditable work. §3.8's
 * list also names „PDF generated", „sent", „payment recorded", „cancelled" and
 * „credited"; each arrives with the operation that can produce it, which is how
 * an entry cannot be written for something that did not happen.
 *
 * „PDF generated" is deliberately not a second case here. It would be written
 * by the same transaction, one statement after this one, and could therefore
 * never be present without it or differ from it — two rows for one fact. The
 * path and the hash ride in this entry's details instead.
 *
 * „Erstellt" is not a case either: a draft is freely editable and is not part
 * of the GoBD record, so the detail page derives that line from `created_at`.
 */
enum AuditEvent: string
{
    case Issued = 'issued';

    public function getLabel(): string
    {
        return __("document.audit.{$this->value}");
    }
}
