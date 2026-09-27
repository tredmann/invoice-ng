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

    'audit' => [
        'issued' => 'Ausgestellt',
    ],

    /*
     * The printed Beleg. These strings go onto a PDF that is frozen the moment
     * it is written and never rendered again, so changing one here changes only
     * documents issued from now on — every Beleg already issued keeps the
     * wording its customer received. That is the point of freezing the file
     * (§4), not a limitation of it.
     */
    'pdf' => [
        'title' => 'Rechnung',
        'number' => 'Rechnungsnummer',
        'customer_number' => 'Kundennummer',
        'issued_on' => 'Rechnungsdatum',
        'performed_on' => 'Leistungsdatum',
        'performed_period' => 'Leistungszeitraum',
        'period' => ':from bis :to',
        'due_on' => 'Fällig am',
        'payment_term' => 'Zahlungsziel',

        'position' => 'Pos.',
        'description' => 'Bezeichnung',
        'quantity' => 'Menge',
        'unit_price' => 'Einzelpreis',
        'tax_rate' => 'USt.',
        'line_net' => 'Betrag',

        'net' => 'Nettobetrag',
        'tax_group' => 'zzgl. :rate USt. auf :base',
        'gross' => 'Gesamtbetrag',

        'intro' => 'wir bedanken uns für die Zusammenarbeit und stellen die folgenden Leistungen in Rechnung.',
        'salutation' => 'Sehr geehrte Damen und Herren,',
        'closing' => 'Bitte überweisen Sie den Gesamtbetrag unter Angabe der Rechnungsnummer bis zum :date.',
        'closing_immediate' => 'Bitte überweisen Sie den Gesamtbetrag unter Angabe der Rechnungsnummer.',
        'regards' => 'Mit freundlichen Grüßen',

        /*
         * §19 Abs. 1 Satz 4 UStG requires the note; it does not prescribe the
         * wording. This is the formulation the Finanzverwaltung's own guidance
         * uses.
         */
        'small_business_note' => 'Gemäß § 19 Abs. 1 UStG wird keine Umsatzsteuer berechnet.',

        'page' => 'Seite :page von :pages',

        'footer' => [
            'register' => ':court, :number',
            'directors' => 'Geschäftsführung: :names',
            'tax_number' => 'Steuernummer: :number',
            'vat_id' => 'USt-IdNr.: :number',
            'bank' => 'Bankverbindung',
            'iban' => 'IBAN: :iban',
            'bic' => 'BIC: :bic',
        ],
    ],
];
