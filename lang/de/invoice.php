<?php

declare(strict_types=1);

return [
    'label' => 'Rechnung',
    'plural_label' => 'Rechnungen',

    'list' => [
        'empty' => 'Noch keine Rechnungen.',
        'empty_help' => 'Rechnungen entstehen als Entwurf und bekommen ihre Nummer erst beim Ausstellen.',
        'no_results' => 'Keine Rechnung gefunden.',
        'no_results_help' => 'Andere Suche versuchen.',
    ],

    'actions' => [
        'create' => 'Neue Rechnung',
        'open' => 'Öffnen',
        'edit' => 'Bearbeiten',
        'delete' => 'Löschen',
    ],

    'columns' => [
        'number' => 'Nummer',
        'status' => 'Status',
        'customer' => 'Kunde',
        'issued_on' => 'Datum',
        'due_on' => 'Fällig',
        'total' => 'Betrag',
    ],

    // A draft has neither a Belegnummer nor a Fälligkeitsdatum. Both come with
    // the Ausstellen, so the column shows what is true now rather than empty.
    'not_yet' => '—',
];
