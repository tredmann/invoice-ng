<?php

declare(strict_types=1);

/**
 * Die Übersicht und ihre Kennzahlen (system design §9).
 *
 * Auch die Heimat der Kennzahlen-Beschriftungen, die die Kundenseite mitbenutzt
 * — dieselben drei Kacheln stehen dort unter den Stammdaten, und zwei Dateien
 * mit „Offene Forderungen" wären zwei Stellen, an denen eine Umbenennung
 * hängenbleiben kann.
 */
return [

    'title' => 'Übersicht',

    'stats' => [
        'revenue_month' => 'Umsatz :month',
        'revenue_year' => 'Umsatz :year',
        // Netto steht dabei, weil die Kachelreihe netto (Umsatz) und brutto
        // (Forderungen) nebeneinanderstellt. Ohne den Hinweis addiert man die
        // beiden irgendwann.
        'revenue_month_note' => 'Netto',
        'revenue_year_note' => 'Netto, seit 01.01.:year',
        'open' => 'Offene Forderungen',
        'overdue' => 'Überfällig',
        'invoice_count' => '{0}0 Rechnungen|{1}1 Rechnung|[2,*]:count Rechnungen',
        'change' => ':percent % ggü. :month',
    ],

    'chart' => [
        'heading' => 'Umsatzverlauf',
        'description' => 'Netto, letzte 12 Monate',
    ],

    'drafts' => [
        'heading' => 'Entwürfe',
        'description' => 'Warten auf Ausstellung',
        'all' => 'Alle Entwürfe',
        'empty' => 'Kein Entwurf offen.',
    ],

    'overdue' => [
        'heading' => 'Überfällige Rechnungen',
        'all' => 'Alle offenen Posten',
        'number' => 'Nummer',
        'customer' => 'Kunde',
        'due' => 'Fällig seit',
        'total' => 'Betrag',
        'since' => 'seit :days Tagen',
        'since_one' => 'seit einem Tag',
        'empty' => 'Nichts überfällig.',
    ],

];
