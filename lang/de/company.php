<?php

declare(strict_types=1);

return [
    'legal_form' => [
        'gmbh' => 'GmbH',
        'ug' => 'UG (haftungsbeschränkt)',
        'sole_proprietorship' => 'Einzelunternehmen',
    ],

    'vat_scheme' => [
        'standard' => 'Regelbesteuerung',
        'small_business' => 'Kleinunternehmer (§19 UStG)',
    ],

    'errors' => [
        'iban' => 'Diese IBAN ist ungültig.',
    ],

    'register' => [
        'title' => 'Neue Firma',
        'name_help' => 'Der vollständige rechtliche Name. Er erscheint auf der Rechnung.',
    ],

    'picker' => [
        'title' => 'Firma wählen',
        'empty' => 'Noch keine Firma angelegt.',
    ],

    'settings' => [
        'title' => 'Firmendaten',
    ],

    'resource' => [
        'label' => 'Firma',
        'plural_label' => 'Firmen',
    ],

    'actions' => [
        'open' => 'Öffnen',
        'archive' => 'Deaktivieren',
        'unarchive' => 'Wieder aktivieren',
        'create' => 'Neue Firma',
        'manage' => 'Firmen verwalten',
        'all' => 'Alle Firmen',
    ],

    'list' => [
        'empty' => 'Noch keine Firma angelegt.',
    ],

    'sections' => [
        'identity' => 'Firma',
        'address' => 'Adresse',
        'tax' => 'Steuer',
        'register' => 'Handelsregister',
        'management' => 'Geschäftsführung',
        'bank' => 'Bankverbindung',
    ],

    'fields' => [
        'name' => 'Name',
        'legal_form' => 'Rechtsform',
        'slug' => 'Kurzname (URL)',
        'slug_help' => 'Wird einmal aus dem Namen gebildet und ändert sich nie.',
        'street' => 'Straße und Hausnummer',
        'postal_code' => 'PLZ',
        'city' => 'Ort',
        'vat_scheme' => 'Besteuerung',
        'tax_number' => 'Steuernummer',
        'vat_id' => 'USt-IdNr.',
        'register_court' => 'Registergericht',
        'register_number' => 'Registernummer',
        'managing_directors' => 'Geschäftsführer',
        'managing_directors_help' => 'Mehrere durch Komma trennen. Erscheint so auf der Rechnung.',
        'bank_name' => 'Bank',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'archived_at' => 'Deaktiviert',
    ],
];
