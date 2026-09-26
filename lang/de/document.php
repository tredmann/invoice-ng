<?php

declare(strict_types=1);

/*
 * The Beleg itself. The Rechnung's own screens live in `invoice.php`; what is
 * here is shared by every Belegart.
 */
return [
    'status' => [
        'draft' => 'Entwurf',
        'issued' => 'Ausgestellt',
        'sent' => 'Versendet',
        'paid' => 'Bezahlt',
        'cancelled' => 'Storniert',
    ],
];
