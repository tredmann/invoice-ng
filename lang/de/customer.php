<?php

declare(strict_types=1);

return [
    'label' => 'Kunde',
    'plural_label' => 'Kunden',

    'type' => [
        'business' => 'Firma',
        'private_person' => 'Privatperson',
    ],

    'status' => [
        'archived' => 'Deaktiviert',
    ],

    'list' => [
        'empty' => 'Noch keine Kunden angelegt.',
        'empty_description' => 'Jede Rechnung geht an einen Kunden. Legen Sie den ersten an.',
        'no_results' => 'Keine Kunden gefunden.',
    ],

    'actions' => [
        'create' => 'Neuer Kunde',
        'open' => 'Öffnen',
        'edit' => 'Bearbeiten',
        'archive' => 'Deaktivieren',
        'unarchive' => 'Wieder aktivieren',
    ],

    'sections' => [
        'customer' => 'Kunde',
        'address' => 'Adresse',
        'contact' => 'Kontakt',
    ],

    'fields' => [
        'number' => 'Kundennr.',
        'type' => 'Typ',
        'name' => 'Name',
        'contact_person' => 'Ansprechpartner',
        'vat_id' => 'USt-IdNr.',
        'street' => 'Straße und Hausnummer',
        'postal_code' => 'PLZ',
        'city' => 'Ort',
        'email' => 'E-Mail',
    ],
];
