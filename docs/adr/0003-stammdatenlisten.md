# Drei Stammdatenlisten, drei Formen

**Steuersätze** sind eine Tabelle je **Firma**, **Zahlungsziele** und
**Einheiten** sind PHP-Enums. Alle drei lesen sich wie „eine Liste zum
Auswählen", und dass sie trotzdem verschieden modelliert sind, sieht von außen
nach Unentschlossenheit aus. Es ist keine: jede der drei Listen beantwortet die
Frage „wer darf sie ändern?" anders, und daran hängt die Form.

- **Steuersatz → Tabelle.** Der **Benutzer** pflegt sie je Firma. Spec §3.4
  zählt Steuersätze zu dem, was **deaktiviert** und nie gelöscht wird — das
  ergibt nur für Zeilen einen Sinn. Der Mockup hat folgerichtig ein
  „+ Steuersatz hinzufügen".
- **Zahlungsziel → Enum.** Die Menge der üblichen deutschen Zahlungsziele ist
  klein und geschlossen, und der Mockup bietet keine Möglichkeit, eines
  anzulegen. Jeder Fall ist eine Dauer plus eine Formulierung, die wörtlich auf
  den **Beleg** gedruckt wird.
- **Einheit → Enum.** Spec §3.3 nennt die Einheitenliste ausdrücklich „a
  standard, not per-company data, and … not user-editable". Der Backing Value
  ist der UN/ECE-Code selbst (`HUR`, `H87`, `DAY`, `LS`, `KMT`), sodass die
  **Position** den Code trägt und das XML ihn ohne Nachschlagen bekommt.

## Considered Options

- **Alle drei als Tabelle**, der Gleichförmigkeit wegen. Verworfen an Spec §3.6:
  eine **Position** „verweist auf keine Stammdaten, sondern trägt ihre Werte
  selbst". Eine Position mit einem Fremdschlüssel auf `units` widerspricht dem
  direkt — und eine gelöschte oder umbenannte Einheitenzeile würde rückwirkend
  auf ausgestellten **Belegen** stehen, die nach §4 unveränderlich sind. Dazu
  ein Seeder, ein UUID-Schlüssel und eine Migration für fünf Zeilen, die sich
  nie ändern.
- **Alle drei als Enum.** Für Steuersätze verworfen: ein Steuersatz wird
  deaktiviert statt gelöscht (§3.4), trägt eine frei wählbare **Bezeichnung**
  und eine Standard-Markierung je Firma. Ein Enum kann nichts davon, und ein
  Steuersatzwechsel des Gesetzgebers wäre ein Deployment statt einer Eingabe.
- **Zahlungsziel als Tabelle**, damit der Benutzer eigene anlegen kann. Verworfen
  als YAGNI: niemand hat danach gefragt, der Mockup zeigt eine reine Auswahl,
  und die Nachrüstung ist eine Migration von einem `string` auf einen
  Fremdschlüssel — nicht das, was sich schwer zurücknehmen lässt.

## Consequences

- **Die Tech-Stack-Spec §12 ist an dieser Stelle falsch.** Sie führt
  `PaymentTerm` und `Unit` unter `app/Models` auf. Diese Liste entstand, bevor
  §3.6 dagegen gehalten wurde; sie wird korrigiert, nicht befolgt. `TaxRate` und
  `NumberRange` bleiben dort richtig.
- **Ein neuer Steuersatz ist eine Eingabe, eine neue Einheit ein Deployment.**
  Das ist die bewusste Asymmetrie. Sollte je eine sechste Einheit gebraucht
  werden, kostet sie einen Enum-Case und eine Zeile in `lang/de/unit.php` —
  keine Migration, weil die **Position** den Code trägt.
- **Der Steuersatz wird in Basispunkten gespeichert** (1900 für 19 %), aus
  demselben Grund, aus dem Geld in Cent gespeichert wird: an eine ganze Zahl
  kommt kein Float heran. Formatiert und geparst wird an einer Stelle auf dem
  Modell, wie `Customer::formatNumber()` es für die Kundennummer tut.
- **„Höchstens ein Standard je Firma" ist eine Datenbankeigenschaft**, kein
  Hook: ein partieller Unique-Index `unique (company_id) where is_default`. Der
  Hook, der die anderen Zeilen zurücksetzt, wird trotzdem geschrieben — er ist,
  was die Oberfläche brauchbar macht; der Index ist, was ihr Versagen überlebt.
  Dasselbe Verhältnis wie Sperre und Unique-Index bei der Kundennummer.
