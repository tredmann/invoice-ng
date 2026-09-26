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

    'sections' => [
        'header' => 'Kopfdaten',
        'positions' => 'Positionen',
        'positions_help' => 'Frei erfasst – es gibt keinen Artikelstamm.',
    ],

    'fields' => [
        'customer' => 'Kunde',
        'issued_on' => 'Rechnungsdatum',
        'payment_term' => 'Zahlungsziel',
        'performed_from' => 'Leistungsdatum',
        'performed_to' => 'Leistung bis (optional)',
        'performed_help' => 'Ein Zeitraum oder ein einzelnes Datum – für die ganze Rechnung, nicht je Position. Der Beleg trägt eines von beiden.',
        'title' => 'Bezeichnung',
        'description' => 'Beschreibung',
        'quantity' => 'Menge',
        'unit' => 'Einheit',
        'unit_price' => 'Einzelpreis',
        'tax_rate' => 'Steuer',
        'net' => 'Netto',
    ],

    'placeholders' => [
        'title' => 'z. B. Konzeption Netzwerkplanung',
        'description' => 'Beschreibung (optional)',
    ],

    'positions' => [
        'add' => 'Position hinzufügen',
        'subtotal' => 'Zwischensumme netto',
        'tax' => 'USt :rate (auf :base)',
        'total' => 'Gesamtbetrag',
    ],

    'create' => [
        'subheading' => 'Entwurf – noch ohne Nummer.',
        'save' => 'Entwurf speichern',
        'number_note' => 'Die Nummer wird erst beim Ausstellen vergeben.',
        'no_customers' => 'Diese Firma hat noch keine Kunden. Jede Rechnung geht an einen Kunden.',
    ],

    'view' => [
        'draft_heading' => 'Entwurf',
        'beleg' => 'Beleg',
        'recipient' => 'Rechnungsempfänger',
        'customer_number' => 'Kundennr. :number',
        'number' => 'Nummer',
        'issued_on' => 'Rechnungsdatum',
        'performed' => 'Leistungszeitraum',
        'performed_single' => 'Leistungsdatum',
        'payment_term' => 'Zahlungsziel',
        'draft_note' => 'Entwurf – frei änderbar. Nummer, Festschreibung und PDF entstehen erst beim Ausstellen.',
        'status' => 'Status',
        'total' => 'Betrag',
        'due' => 'Fällig',
        'due_after_issue' => ':term ab Ausstellung',
        'history' => 'Verlauf',
        'created' => 'Erstellt',
        'issue' => 'Rechnung ausstellen',
        'issue_disabled' => 'Das Ausstellen ist noch nicht gebaut.',
    ],

    'delete' => [
        'heading' => 'Entwurf löschen?',
        'body' => 'Der Entwurf hat noch keine Nummer, also bleibt nichts zurück. Ausgestellte Belege lassen sich nie löschen.',
        'confirm' => 'Entwurf löschen',
        'done' => 'Entwurf gelöscht.',
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
