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
    ],

    'fields' => [
        'customer' => 'Kunde',
        'issued_on' => 'Rechnungsdatum',
        'payment_term' => 'Zahlungsziel',
        'performed_from' => 'Leistungsdatum',
        'performed_to' => 'Leistung bis (optional)',
        'due_hint' => 'Fällig am :date',
        'position' => 'Pos.',
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
        'status' => 'Status',
        'total' => 'Betrag',
        'due' => 'Fällig',
        'history' => 'Verlauf',
        'created' => 'Erstellt',
        'issue' => 'Rechnung ausstellen',
        'download' => 'PDF herunterladen',
        'overdue' => 'Überfällig',
        'due_on' => 'Fällig am :date',
    ],

    'issue' => [
        'heading' => 'Rechnung ausstellen?',
        'body' => 'Die Rechnung bekommt jetzt ihre Nummer und ein PDF. Danach lässt sich nichts mehr daran ändern und sie lässt sich nicht mehr löschen – ein Fehler kostet ein Storno.',
        'confirm' => 'Ausstellen',
        'done' => 'Rechnung :number ausgestellt.',
        'failed' => 'Die Rechnung konnte nicht ausgestellt werden.',
        'failed_body' => 'Es wurde keine Nummer vergeben; der Entwurf ist unverändert.',

        'blocked_heading' => 'Noch nicht bereit',
        'blocked_body' => 'Diese Angaben verlangt § 14 UStG auf jeder Rechnung. Ohne sie lässt sich keine ausstellen:',
        'blocked_action' => 'Zu den Einstellungen',
        'warnings_heading' => 'Empfohlen, aber kein Hindernis',
    ],

    'audit' => [
        'issued' => 'Ausgestellt',
        'number' => 'Nummer :number',
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
