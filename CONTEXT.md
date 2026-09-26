# Rechnungsstellung

Die Fachsprache der ausgehenden Rechnungsstellung eines Mehrfirmen-Betriebs
nach deutschem Recht. Kanonisch ist der deutsche Begriff — er ist der, den
UStG, GoBD und der Steuerberater benutzen. Der englische Identifier daneben
ist die Entsprechung im Code; die Übersetzung wird hier einmal entschieden
und nicht pro Spalte neu erfunden (siehe `CLAUDE.md`, „Identifiers are
English").

## Sprache

### Belege

**Beleg**:
Sammelbegriff für jedes von uns ausgestellte Dokument, das einen
Geschäftsvorfall nachweist.
_Code_: `Document`
_Vermeiden_: Dokument (zu allgemein — ein PDF ist auch eines)

**Rechnung**:
Zahlungsaufforderung an einen **Kunden** für eine erbrachte Leistung.
_Code_: `Invoice`
_Vermeiden_: Faktura, Beleg (das ist der Oberbegriff), Ausgangsrechnung

**Storno**:
Nimmt eine ausgestellte **Rechnung** vollständig zurück; die Rechnung ist
danach erledigt, und nichts nimmt ein Storno zurück.
_Code_: `Cancellation`
_Vermeiden_: Stornorechnung, Stornierung, Gutschrift

**Teilstorno**:
Nimmt einen Teil einer ausgestellten **Rechnung** zurück; die Rechnung
bleibt für den Rest offen und zahlbar.
_Code_: `PartialCancellation`
_Vermeiden_: **Rechnungskorrektur**, Gutschrift, kaufmännische Gutschrift,
Entgeltminderung

**Berichtigung**:
Trägt fehlende oder falsche **Angaben** einer ausgestellten **Rechnung**
nach, ohne den Betrag zu ändern (§31 Abs. 5 UStDV); die Rechnung bleibt
gültig. *Noch nicht gebaut — der Begriff ist reserviert.*
_Code_: `Correction` (noch nicht gebaut)
_Vermeiden_: Rechnungsberichtigung, Korrektur, berichtigte Rechnung

**Gutschrift**:
Abrechnung, die wir als Leistungsempfänger über eine uns erbrachte
Leistung ausstellen (§14 Abs. 2 Satz 2 UStG).
_Code_: `SelfBilledInvoice`
_Nicht verwechseln mit_: **Teilstorno**

### Nummerierung

**Belegnummer**:
Die fortlaufende Nummer, die ein **Beleg** beim **Ausstellen** erhält und
die danach nie mehr wechselt (§14 Abs. 4 Nr. 2 UStG).
_Vermeiden_: Rechnungsnummer (nur eine der Belegarten), ID

**Nummernkreis**:
Die lückenlose Folge, aus der **Belegnummern** gezogen werden — einer je
**Firma**, gemeinsam für alle Belegarten, die Rechnungen im Sinne des §14
UStG sind.
_Vermeiden_: Sequenz, Zähler

### Mahnwesen

**Mahnung**:
Zahlungsaufforderung zu einer überfälligen **Rechnung** (§286 Abs. 1 BGB) —
**kein Beleg**, weil sie keinen Geschäftsvorfall nachweist, sondern an einen
erinnert.
_Code_: `DunningNotice`
_Vermeiden_: **Zahlungserinnerung** (das ist der Titel der ersten
**Mahnstufe**, nicht die Art), Mahnschreiben, Zahlungsaufforderung

**Mahnstufe**:
Eine Konfigurationszeile, die Titel und Ton einer **Mahnung** bestimmt;
Stufe 1 trägt den Titel „Zahlungserinnerung".
_Code_: `DunningLevel`
_Vermeiden_: Mahnlevel, Eskalationsstufe, Mahnphase

### Beteiligte

**Benutzer**:
Wer die Anwendung bedient und zu einer oder mehreren **Firmen** gehört.
_Code_: `User`
_Vermeiden_: **Kunde** (das ist der Rechnungsempfänger), Nutzer, Anwender

**Firma**:
Ein eigener Betrieb des **Benutzers**, mit eigenen **Kunden**, eigenem
**Nummernkreis** und eigener Identität auf dem **Beleg**.
_Code_: `Company`
Nach §17 HGB ist die Firma streng genommen der *Name* des Kaufmanns; wir
folgen dem bewusst nicht, weil umgangssprachlich der Betrieb gemeint ist.
_Vermeiden_: Mandant, Tenant, Organisation

**Kunde**:
Empfänger einer **Rechnung** — entweder **Geschäftskunde** oder
**Privatkunde**.
_Code_: `Customer`
_Vermeiden_: Klient, Auftraggeber, Konto

**Geschäftskunde**:
Ein **Kunde**, der unternehmerisch handelt; trägt Ansprechpartner und
USt-IdNr.
_Code_: `CustomerType::Business`
_Vermeiden_: **Firma** (das ist der eigene Betrieb), Firmenkunde,
Gewerbekunde, B2B

**Privatkunde**:
Ein **Kunde**, der nicht unternehmerisch handelt. Der Enum-Case heißt weiter
`PrivatePerson`: sein Backing Value `private_person` steht in der Datenbank,
und ihn umzubenennen hätte eine Datenmigration für ein internes Wort
gekostet.
_Code_: `CustomerType::PrivatePerson`
_Vermeiden_: Privatperson, Verbraucher, Endkunde, B2C

**Vermittler**:
Wer uns einen **Kunden** vermittelt; uns gegenüber leistender Unternehmer,
nicht **Kunde** — auch dann nicht, wenn dieselbe Person zusätzlich Kunde
ist.
_Code_: `Referrer`
_Vermeiden_: Lieferant, Partner, Affiliate

### Leistungen und Entgelte

**Vermittlungsleistung**:
Was ein **Vermittler** uns erbringt, indem er einen **Kunden** vermittelt.
_Vermeiden_: **Leistungsvermittlung** (siehe Geklärte Mehrdeutigkeiten),
Empfehlung, Tipp

**Vermittlungsprovision**:
Das einmalige Entgelt für eine **Vermittlungsleistung**, fällig je
vermitteltem **Kunden**, nicht je Auftrag. Für uns eine Betriebsausgabe,
kein geminderter Umsatz.
_Code_: `ReferralCommission`
_Vermeiden_: Gutschrift (das ist der Beleg, nicht das Entgelt), Rabatt,
Nachlass, Kickback

### Positionen

**Position**:
Eine Zeile auf einem **Beleg** — Bezeichnung, Menge, **Einheit**,
**Einzelpreis** und **Steuersatz**; sie verweist auf keine Stammdaten,
sondern trägt ihre Werte selbst.
_Code_: `LineItem`
_Vermeiden_: Zeile, Artikel, Posten, Leistung (zu allgemein)

**Einheit**:
Die Maßeinheit einer **Position**, aus einer festen, nicht bearbeitbaren
Liste mit UN/ECE-Code (Stück, Stunde, Tag, Pauschal, km).
_Code_: `Unit`
_Vermeiden_: Mengeneinheit, Maß

**Einzelpreis**:
Der **Nettobetrag** einer **Einheit**.
_Code_: `unit_price`
_Vermeiden_: Preis, Stückpreis (gilt nur für eine der Einheiten)

### Geld und Steuer

**Nettobetrag**:
Betrag ohne **Umsatzsteuer**.
_Code_: `net`
_Vermeiden_: Warenwert, Grundpreis

**Steuersatz**:
Der Umsatzsteuersatz einer **Position** — je Position eigen, sodass ein
**Beleg** 19 % und 7 % mischen kann.
_Code_: `TaxRate`
_Vermeiden_: Steuerklasse, Steuerschlüssel

**Umsatzsteuer**:
Die auf eine **Bemessungsgrundlage** entfallende Steuer.
_Code_: `tax`
_Vermeiden_: Mehrwertsteuer und MwSt. (umgangssprachlich; das UStG kennt
nur die Umsatzsteuer), VAT

**Bemessungsgrundlage**:
Die Summe der **Nettobeträge** aller **Positionen** eines **Steuersatzes**,
auf die die **Umsatzsteuer** einmal gerundet berechnet wird (§10 UStG).
_Vermeiden_: Basis, Zwischensumme, Netto-Summe

**Bruttobetrag**:
**Nettobetrag** plus **Umsatzsteuer** — was der **Kunde** zahlt.
_Code_: `gross`
_Vermeiden_: Endbetrag, Gesamtpreis, Rechnungsbetrag

**Regelbesteuerung**:
Die **Firma** weist **Umsatzsteuer** aus.
_Code_: `VatScheme::Standard`

**Kleinunternehmer**:
Die **Firma** weist nach §19 UStG keine **Umsatzsteuer** aus; ihre **Belege**
tragen 0 % und den vorgeschriebenen Hinweis.
_Code_: `VatScheme::SmallBusiness`
_Vermeiden_: Kleingewerbe, Kleinunternehmen

**Zahlung**:
Ein eingegangener Betrag zu einem **Beleg**, mit Datum und Notiz — eine
Zeile, kein Schalter.
_Code_: `Payment`
_Vermeiden_: Zahlungseingang, Überweisung, Bezahlung

**offener Betrag**:
Was von einer **Rechnung** noch aussteht: **Bruttobetrag** minus
**Zahlungen** minus **Teilstornos**.
_Code_: `open_amount`
_Vermeiden_: Restbetrag, Saldo, Differenz

**Offene Posten**:
Die Liste aller **Rechnungen** mit einem **offenen Betrag**.
_Vermeiden_: Forderungen, Außenstände, Debitoren

### Daten und Fristen

**Rechnungsdatum**:
Der Tag, an dem der **Beleg** **ausgestellt** wurde — im Gesetz das
Ausstellungsdatum (§14 Abs. 4 Nr. 3 UStG).
_Code_: `issued_on`
_Vermeiden_: Belegdatum, Erstellungsdatum, Druckdatum

**Leistungsdatum**:
Der Tag, an dem die Leistung erbracht wurde (§14 Abs. 4 Nr. 6 UStG);
in ZUGFeRD BT-72.
_Code_: `performed_on`
_Vermeiden_: Lieferdatum (wir liefern keine Waren), Leistungstag

**Leistungszeitraum**:
Anfang und Ende des Zeitraums, in dem die Leistung erbracht wurde; in
ZUGFeRD BG-14 (BT-73/BT-74). Ein nach §31 Abs. 4 UStDV zulässiger
Kalendermonat ist ein Leistungszeitraum, kein eigener Begriff.
_Code_: `performed_from` / `performed_to`
_Vermeiden_: Abrechnungszeitraum, Leistungsperiode

**Zahlungsziel**:
Die Frist, innerhalb der eine **Rechnung** zu zahlen ist — eine Dauer
(„14 Tage"), kein Datum.
_Code_: `payment_term`
_Vermeiden_: Zahlungsbedingung, Zahlungsfrist, **Fälligkeitsdatum**

**Fälligkeitsdatum**:
Der Tag, an dem eine **Rechnung** fällig wird — **Rechnungsdatum** plus
**Zahlungsziel**.
_Code_: `due_on`
_Vermeiden_: **Zahlungsziel** (das ist die Dauer), Frist, Verfallsdatum

### Vorgänge

**ausstellen**:
Macht aus einem **Entwurf** einen gültigen **Beleg** — zieht die
**Belegnummer**, friert die Identitäten ein, erzeugt das PDF und macht
alles unveränderlich; eine Transaktion, ganz oder gar nicht.
_Code_: `issue`
_Vermeiden_: erteilen (das ist im UStG die Übergabe an den Kunden, also
**versenden**), schreiben, festschreiben, freigeben, buchen

**versenden**:
Schickt das gespeicherte PDF eines ausgestellten **Belegs** per E-Mail an
den **Kunden** — ein eigener Vorgang nach dem **Ausstellen**, wiederholbar.
_Code_: `send`
_Vermeiden_: erteilen, zustellen, verschicken

**stornieren**:
Nimmt eine **Rechnung** vollständig zurück: stellt ein **Storno** aus,
setzt die Rechnung auf **storniert** und öffnet einen neuen **Entwurf** mit
denselben Positionen.
_Code_: `cancel`
_Vermeiden_: korrigieren, berichtigen, löschen, rückgängig machen

**deaktivieren**:
Nimmt einen **Kunden** oder eine **Firma** aus allen Auswahllisten, ohne
etwas zu löschen — auf bestehenden **Belegen** bleibt alles stehen.
_Code_: `deactivate`
_Vermeiden_: **archivieren** (siehe unten), löschen, stilllegen, sperren

**Archivierung**:
Die gesetzliche Aufbewahrung ausgestellter **Belege** über zehn Jahre
(§147 AO, §14b UStG). *Noch nicht gebaut — der Begriff ist reserviert.*
_Vermeiden_: das Wort für das **Deaktivieren** von Kunden oder Firmen zu
verwenden

### Zustände

**Entwurf**:
Ein **Beleg** ohne **Belegnummer** — frei änderbar und löschbar.
_Code_: `draft`

**ausgestellt**:
Unveränderlich, mit **Belegnummer** und PDF, aber noch nicht beim
**Kunden**.
_Code_: `issued`

**versendet**:
Ausgestellt und per E-Mail hinausgegangen.
_Code_: `sent`

**bezahlt**:
Der offene Betrag ist auf null.
_Code_: `paid`

**storniert**:
Durch ein **Storno** zurückgenommen und dauerhaft erledigt.
_Code_: `cancelled`

**deaktiviert**:
Ein **Kunde** oder eine **Firma**, die nicht mehr auswählbar ist, aber auf
bestehenden **Belegen** weiterlebt.
_Code_: `deactivated_at`

## Beziehungen

- **Rechnung**, **Storno**, **Teilstorno** und **Gutschrift** sind **Belege**
  und ziehen ihre **Belegnummer** aus demselben **Nummernkreis**
- Ein **Storno** und ein **Teilstorno** beziehen sich auf genau eine
  **Rechnung**; eine Rechnung trägt höchstens ein **Storno**, aber beliebig
  viele **Teilstornos**
- Eine **Rechnung** geht an genau einen **Kunden**
- Eine **Gutschrift** geht an genau einen **Vermittler** und rechnet über
  dessen **Vermittlungsleistungen** ab
- Ein vermittelter **Kunde** löst genau eine **Vermittlungsprovision** aus —
  einmalig, unabhängig davon, wie viele Aufträge daraus folgen
- **Rechnung** und **Gutschrift** laufen in entgegengesetzte
  Leistungsrichtungen: auf der **Rechnung** sind wir der leistende
  Unternehmer, auf der **Gutschrift** ist es der **Vermittler**. Ausgestellt
  und versendet werden beide von uns.
- Eine **Mahnung** bezieht sich auf genau eine überfällige **Rechnung**,
  zieht ihre Nummer aus einem **eigenen Nummernkreis** und ist kein **Beleg**
- **ausstellen** und **versenden** sind getrennte Vorgänge: ein
  ausgestellter, nicht versendeter **Beleg** ist ein normaler Zustand, ein
  versendeter ohne Ausstellung unmöglich
- Ein **Beleg** trägt eine oder mehrere **Positionen**; jede trägt ihren
  eigenen **Steuersatz**, sodass ein Beleg 19 % und 7 % mischen kann
- **Positionen** werden je **Steuersatz** zu einer **Bemessungsgrundlage**
  summiert, und die **Umsatzsteuer** wird je Gruppe einmal gerundet — nicht
  je Position und nicht am Ende
- Ein **Beleg** trägt entweder ein **Leistungsdatum** oder einen
  **Leistungszeitraum**, niemals beides; **Positionen** tragen keines von
  beiden
- Eine **Rechnung** kann mehrere **Zahlungen** tragen; ihr **offener
  Betrag** folgt daraus, statt von Hand gesetzt zu werden
- Ein **Benutzer** gehört zu mehreren **Firmen**; jede **Firma** hat eigene
  **Kunden** und einen eigenen **Nummernkreis**. Nichts wird zwischen
  Firmen geteilt.

## Abgrenzungen

- **Welcher Rückweg wann:** ändert sich der **Betrag** ganz, ist es ein
  **Storno**; ändert er sich teilweise, ein **Teilstorno**; bleibt er gleich
  und nur eine **Angabe** war falsch, ist es eine **Berichtigung**. Drei
  Fälle, drei Wörter, kein Ermessen.
- **Was in den gemeinsamen Nummernkreis gehört:** jeder **Beleg**, der eine
  Rechnung im Sinne des §14 UStG ist. Das trifft auch auf die **Gutschrift**
  zu — sie ist eine Rechnung, nur von uns als Leistungsempfänger
  ausgestellt.
- **Wann eine Gutschrift das richtige Instrument ist:** wenn nur der
  Zahlende den Betrag kennt. Wir wissen, welche **Kunden** über einen
  **Vermittler** kamen — er nicht, also rechnen wir ab. Ein Subunternehmer
  dagegen kennt seine Stunden, also rechnet er ab; das ergibt eine
  Eingangsrechnung, und die ist außerhalb dieses Kontexts.
- **Zahlungsziel ist eine Dauer, Fälligkeitsdatum ein Tag.** Die beiden
  werden im Alltag verwechselt; auf dem **Beleg** stehen sie beide, und nur
  eines davon lässt sich konfigurieren.
- **Leistungsdatum oder Leistungszeitraum, nie beides.** ZUGFeRD hat dafür
  zwei verschiedene Strukturen — BT-72 für den Tag, BG-14 für den Zeitraum.
  Ein Feld, das beides aufnimmt, lässt sich auf keine von beiden abbilden.
- **Umsatz und Aufwand liegen in derselben Belegmenge.** Eine **Gutschrift**
  ist Aufwand und zählt nicht in Umsatzsummen; ihre Umsatzsteuer ist für uns
  Vorsteuer, nicht geschuldete Steuer.

## Beispieldialog

> **Entwickler:** „Der Vermittler ist selbst Kunde. Ziehen wir die 50 €
> einfach von seiner nächsten **Rechnung** ab?"
>
> **Fachexperte:** „Nein. Das sind zwei Leistungen in entgegengesetzte
> Richtungen. Seine **Rechnung** läuft in voller Höhe, und die
> **Vermittlungsprovision** steht auf einer eigenen **Gutschrift**.
> Verrechnet wird höchstens die Zahlung, nie der Beleg."
>
> **Entwickler:** „Und wenn der vermittelte Kunde ein zweites Mal
> beauftragt?"
>
> **Fachexperte:** „Dann passiert beim Vermittler nichts. Die
> **Vermittlungsprovision** hängt am **Kunden**, nicht am Auftrag."
>
> **Entwickler:** „Der Kunde sagt, seine USt-IdNr. fehlt auf einer bezahlten
> Rechnung. **Stornieren**?"
>
> **Fachexperte:** „Nein — der Betrag ist richtig. Das ist eine
> **Berichtigung**. Die können wir noch nicht, deshalb bleibt es vorläufig
> beim **Stornieren**; aber nenn es nicht Korrektur, sonst landet es
> irgendwann beim **Teilstorno**."
>
> **Entwickler:** „Und wer legt das Präfix des **Nummernkreises** fest?"
>
> **Fachexperte:** „Der **Benutzer**, je **Firma** — nicht je **Kunde**.
> Es gibt einen Kreis pro Firma, und das Präfix steht auf jedem **Beleg**
> daraus, auch auf dem **Storno** und der **Gutschrift**."

## Geklärte Mehrdeutigkeiten

- **„Gutschrift" bezeichnete zwei entgegengesetzte Dokumente.** Die Spec
  benutzte das Wort für die Teilrücknahme einer eigenen Rechnung. §14 Abs. 2
  Satz 2 UStG belegt es für die Selbstabrechnung durch den
  Leistungsempfänger, und dieser Fall tritt hier tatsächlich auf. Geklärt:
  die Teilrücknahme heißt **Teilstorno**; **Gutschrift** bleibt dem Beleg
  nach §14 Abs. 2 vorbehalten. (Das BMF-Schreiben vom 25.10.2013 stellt
  klar, dass die falsche Bezeichnung allein kein §14c Abs. 2 UStG auslöst —
  es wäre ein Sprach-, kein Steuerproblem gewesen. Dass beide Belege real
  vorkommen, macht die Trennung trotzdem notwendig.)
- **„Rechnungskorrektur" wurde verworfen, nachdem sie schon beschlossen
  war.** Sie war in dieser Sitzung zuerst als Name für die Teilrücknahme
  gesetzt. Dann kam die **Berichtigung** nach §31 Abs. 5 UStDV dazu, und
  „Rechnungskorrektur" neben „Rechnungsberichtigung" wären zwei fast gleich
  klingende Wörter für zwei verschiedene Belege geworden — dieselbe Falle
  wie *Leistungsvermittlung*. Geklärt: **Storno** und **Teilstorno** als
  hörbares Paar, **Berichtigung** daneben. Der Preis ist bekannt:
  lexoffice und sevDesk drucken „Rechnungskorrektur", unser Beleg nicht.
  Entschieden 2026-09-26.
- **„Kunde" wurde für den Benutzer der Anwendung verwendet.** Gesagt wurde
  „der Kunde entscheidet über das Präfix", gemeint war der **Benutzer**.
  Gelesen als **Kunde** ergibt das eine Präfix-Einstellung je
  Rechnungsempfänger und einen zerlegten **Nummernkreis**. Geklärt: die
  Anwendung bedient ein **Benutzer**, eine **Rechnung** empfängt ein
  **Kunde**. Entschieden 2026-09-26.
- **„Leistungsvermittlung" ist nicht die Vermittlungsleistung.** Dieselben
  Bestandteile, vertauschtes Objekt: die **Vermittlungsleistung** (§3a
  Abs. 3 Nr. 4 UStG) ist die Leistung, die im Vermitteln besteht;
  „Leistungsvermittlung" wäre das Vermitteln einer fremden Leistung, also
  Agenturgeschäft. Geklärt: nur **Vermittlungsleistung**. Entschieden
  2026-09-26.
- **„Beleg" wurde für die Rechnung verwendet.** Geklärt: **Beleg** ist der
  Sammelbegriff, die Belegarten behalten ihre eigenen Namen. Wer „Rechnung"
  meint, sagt Rechnung. Entschieden 2026-09-26.
- **„erteilen" ist nicht „ausstellen".** §14 Abs. 1 UStG sagt *ausstellen*
  für das Entstehen des Belegs, §14 Abs. 2 sagt *erteilen* für die Übergabe
  an den Kunden. Da Spec §8.5 beides in getrennte Transaktionen legt,
  heißt der erste Vorgang **ausstellen** und der zweite **versenden**;
  „erteilen" wird gar nicht verwendet. Entschieden 2026-09-26.
- **Nachlass, Retoure und Kulanz gibt es nicht.** Ein **Teilstorno** setzt
  immer einen eigenen Fehler voraus. Eine sachlich richtige **Rechnung**
  wird nicht nachträglich gemindert. Entschieden 2026-09-26.
- **Ein Vermittler ist kein Kunde.** Auch wenn dieselbe Person beides ist,
  sind es zwei Rollen mit zwei Belegen in zwei Richtungen. Entschieden
  2026-09-26.
- **„archivieren" war für das Stilllegen eines Kunden vergeben.** Im
  deutschen Rechnungswesen ist Archivierung die Aufbewahrung der **Belege**
  über zehn Jahre (§147 AO, §14b UStG) — etwas, das diese Anwendung
  tatsächlich tut. Geklärt: Kunden und Firmen werden **deaktiviert**, der
  Code folgt mit `deactivate`; **Archivierung** bleibt der
  Aufbewahrungspflicht vorbehalten. Spec §3.4 trägt noch die Überschrift
  „Archiving" bei einem Text, der „deactivated" sagt. Entschieden
  2026-09-26.
- **Die Spec benutzte „Leistungsdatum" und „Leistungszeitraum" für dasselbe
  Feld.** §7 schreibt „Leistungsdatum (one date or period per invoice)", §11
  schreibt „Leistungszeitraum". Geklärt: zwei Begriffe für zwei Inhalte, ein
  **Beleg** trägt genau einen — weil ZUGFeRD sie auf BT-72 bzw. BG-14
  abbildet und ein gemischtes Feld auf keines von beiden passt. Entschieden
  2026-09-26.
- **„Mehrwertsteuer" kommt nicht vor.** Das UStG kennt nur die
  **Umsatzsteuer**; „Mehrwertsteuer" und „MwSt." sind umgangssprachlich.
  Auf einem Beleg nach §14 UStG steht der gesetzliche Begriff. Entschieden
  2026-09-26.
- **„Mahnung" und „Zahlungserinnerung" bezeichneten dasselbe Papier.** Spec
  §5 nennt die Art Mahnung, §11 setzt „Zahlungserinnerung" als erste Stufe.
  Nach §286 Abs. 1 BGB ist jede eindeutige Zahlungsaufforderung nach
  Fälligkeit eine Mahnung — der gedruckte Titel ändert die Rechtsfolge
  nicht. Geklärt: die Art heißt **Mahnung**, die **Mahnstufe** trägt den
  Titel, Stufe 1 heißt „Zahlungserinnerung". Damit entfällt auch der in
  `CLAUDE.md` geplante Identifier `PaymentReminder` — er übersetzt die
  Stufe, nicht den Beleg — zugunsten von `DunningNotice`. Entschieden
  2026-09-26.
- **Die Mahnung ist kein Beleg.** Ein **Beleg** weist einen
  Geschäftsvorfall nach; eine **Mahnung** erinnert an einen, und aus ihr
  wird nichts gebucht. Sie hat deshalb ein eigenes Modell, eine eigene
  Tabelle und einen eigenen **Nummernkreis**. Entschieden 2026-09-26.
- **„Firma" bezeichnete den eigenen Betrieb und einen Kundentyp.** Die
  Oberfläche nannte den Tenant „Firma" und labelte gleichzeitig den
  Kundentyp `business` mit „Firma". Geklärt: **Firma** ist ausschließlich der
  eigene Betrieb; der Kundentyp heißt **Geschäftskunde**, sein Gegenstück
  **Privatkunde**. Entschieden 2026-09-26.
