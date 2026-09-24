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

    'settings' => [
        'title' => 'Firmendaten',
    ],

    'fields' => [
        'name' => 'Name',
        'legal_form' => 'Rechtsform',
    ],
];
