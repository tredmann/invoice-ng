<?php

declare(strict_types=1);

return [
    'label' => 'Kunde',
    'plural_label' => 'Kunden',

    'type' => [
        'business' => 'Geschäftskunde',
        'private_person' => 'Privatkunde',
    ],

    'status' => [
        'deactivated' => 'Deaktiviert',
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
        'deactivate' => 'Deaktivieren',
        'reactivate' => 'Wieder aktivieren',
        'save' => 'Kunde speichern',
        'new_invoice' => 'Neue Rechnung',
        'new_invoice_disabled' => 'Rechnungen gibt es noch nicht.',
    ],

    'view' => [
        'subheading' => 'Kundennr. :number',
    ],

    'stats' => [
        'revenue' => 'Umsatz :year',
        'revenue_since' => 'seit 01.01.:year',
        'open' => 'Offene Forderungen',
        'overdue' => 'Überfällig',
        'invoice_count' => '{0}0 Rechnungen|{1}1 Rechnung|[2,*]:count Rechnungen',
        'zero' => '0,00 €',
    ],

    'invoices' => [
        'heading' => 'Rechnungen',
        'empty' => 'Noch keine Rechnungen.',
    ],

    'create' => [
        'subheading' => 'Die Kundennummer wird beim Speichern vergeben.',
    ],

    'sections' => [
        'type' => 'Typ',
        'master' => 'Stammdaten',
        'address' => 'Rechnungsanschrift',
        'billing' => 'Rechnungsstellung',

        // Still read by the infolist until it is rebuilt.
        'customer' => 'Kunde',
        'contact' => 'Kontakt',
    ],

    'help' => [
        'type' => 'Bestimmt, welche Angaben die Rechnung braucht – und steht auf dem Beleg.',
        'address' => 'Erscheint unverändert auf jeder Rechnung an diesen Kunden.',
        'vat_id' => 'Nur bei Geschäftskunden.',
        'email' => 'Ohne E-Mail bleibt nur der PDF-Download.',
    ],

    'placeholders' => [
        'business_name' => 'z. B. Weber Haustechnik e.K.',
        'private_name' => 'z. B. Sofia Kraus',
        'contact_person' => 'Optional',
        'vat_id' => 'DE…',
        'street' => 'Musterstraße 1',
        'postal_code' => '90402',
        'city' => 'Nürnberg',
        'email' => 'name@firma.de',
    ],

    'fields' => [
        'number' => 'Kundennr.',
        'type' => 'Typ',
        'name' => 'Name',
        'name_business' => 'Firmenname',
        'billing_address' => 'Rechnungsanschrift',
        'created_at' => 'Kunde seit',
        'contact_person' => 'Ansprechpartner',
        'vat_id' => 'USt-IdNr.',
        'street' => 'Straße und Hausnummer',
        'postal_code' => 'PLZ',
        'city' => 'Ort',
        'email' => 'E-Mail',
    ],
];
