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

    'payment_term' => [
        'immediate' => 'Sofort fällig',
        'net_7' => '7 Tage netto',
        'net_14' => '14 Tage netto',
        'net_30' => '30 Tage netto',
        'net_60' => '60 Tage netto',
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
        'deactivated' => 'Deaktiviert (:count)',
    ],

    'switcher' => [
        'label' => 'Firma wechseln',
        'trigger' => 'Firma wechseln (aktuell: :name)',
        'manage' => 'Firmen verwalten',
    ],

    'dashboard' => [
        'empty' => 'Noch keine Inhalte.',
        'complete_settings' => 'Firmendaten vervollständigen',
    ],

    'settings' => [
        'title' => 'Firmendaten',
    ],

    'actions' => [
        'deactivate' => 'Deaktivieren',
        'reactivate' => 'Wieder aktivieren',
        'create' => 'Neue Firma',
    ],

    'tabs' => [
        'company' => 'Firma',
        'tax' => 'Steuer',
        'bank' => 'Bank',
        'number_range' => 'Nummernkreis',
    ],

    'sections' => [
        'identity' => 'Firma',
        'identity_help' => 'Dieser Block steht im Fuß jeder Rechnung. §14 UStG macht ihn zur Pflicht.',
        'address' => 'Adresse',
        'tax' => 'Steuer',
        'register' => 'Handelsregister',
        'register_help' => 'Pflicht bei eingetragenen Rechtsformen.',
        'management' => 'Geschäftsführung',
        'bank' => 'Bankverbindung',
        'bank_help' => 'Steht im Belegfuß und im ZUGFeRD-Datensatz, damit der Empfänger die Zahlung zuordnen kann.',
        'logo' => 'Logo',
        'logo_help' => 'Erscheint auf dem Beleg. Ein Austausch ändert bereits ausgestellte PDFs nicht.',
        'taxation' => 'Besteuerung',
        'taxation_help' => 'Bestimmt, ob Belege Umsatzsteuer ausweisen.',
        'tax_numbers' => 'Steuernummern',
        'tax_numbers_help' => 'Mindestens eine der beiden Angaben ist Pflicht.',
        'tax_rates' => 'Steuersätze',
        'tax_rates_help' => 'Jede Position trägt ihren eigenen Satz – eine Rechnung darf 19 % und 7 % mischen.',
        'payment_term' => 'Zahlungsziel',
        'payment_term_help' => 'Vorgabe für neue Kunden und neue Rechnungen.',
        'number_range' => 'Nummernkreis',
        'number_range_help' => 'Eine Folge je Firma – gemeinsam für Rechnungen, Stornos, Teilstornos und Gutschriften. Das Präfix steht auf jedem Beleg daraus.',
    ],

    'tax_rate' => [
        'seed' => [
            'standard' => 'Regelsatz',
            'reduced' => 'Ermäßigter Satz',
            'exempt' => 'Steuerfrei',
        ],
        'add' => 'Steuersatz hinzufügen',
        'columns' => [
            'rate' => 'Satz',
            'name' => 'Bezeichnung',
            'default' => 'Standard',
        ],
        'small_business' => 'Als Kleinunternehmer wird mit 0 % ausgestellt, ohne USt-Block und mit dem vorgeschriebenen §19-Hinweis. Steuersätze werden dafür nicht gebraucht.',
        'duplicate' => 'Diesen Steuersatz gibt es schon.',
    ],

    'number_range' => [
        'warning' => 'Die Folge ist lückenlos: Die Nummer wird erst beim Ausstellen unter Sperre gezogen und bei einem Fehler zurückgerollt. Bereits vergebene Nummern ändern sich nie – eine Änderung hier wirkt ab der nächsten Ausstellung.',
        'start_locked' => 'Es wurden bereits Nummern vergeben. Der Startwert lässt sich nur noch erhöhen, nie senken.',
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
        'payment_term' => 'Zahlungsziel',
        'logo_path' => 'Logo',
        'prefix' => 'Präfix',
        'padding' => 'Stellen',
        'padding_help' => '0001, 0002, …',
        'next_value' => 'Startwert',
        'next_value_help' => 'Für den Umstieg.',
        'include_year' => 'Jahr in der Nummer führen',
        'include_year_help' => 'RE-2026-0043 statt RE-0043.',
        'reset_yearly' => 'Jährlich zurücksetzen',
        'reset_yearly_help' => 'Am 1. Januar beginnt die Zählung wieder bei 1.',
        'next_number' => 'Nächste Nummer',
        'bank_name' => 'Bank',
        'iban' => 'IBAN',
        'bic' => 'BIC',
        'deactivated_at' => 'Deaktiviert',
    ],
];
