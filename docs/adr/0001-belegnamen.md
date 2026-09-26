# Belegnamen: Storno, Teilstorno, Berichtigung, Gutschrift

Die Rücknahme einer Rechnung heißt **Storno** (vollständig) bzw.
**Teilstorno** (teilweise); eine reine Angabenkorrektur nach §31 Abs. 5
UStDV heißt **Berichtigung**; **Gutschrift** bleibt ausschließlich der
Selbstabrechnung durch den Leistungsempfänger nach §14 Abs. 2 Satz 2 UStG
vorbehalten. Wir geben damit „Rechnungskorrektur" auf — das Wort, das
lexoffice und sevDesk auf genau diesen Beleg drucken — weil es neben
„Rechnungsberichtigung" zwei fast gleich klingende Namen für zwei
verschiedene Belege ergeben hätte. Diese Namen werden auf eingefrorene PDFs
gedruckt (Spec §4) und sind nach dem ersten ausgestellten Beleg nicht mehr
änderbar.

## Considered Options

- **„Gutschrift" für die Teilrücknahme**, wie die ursprüngliche System-Spec
  (§3.5, §8.3). Verworfen: §14 Abs. 2 Satz 2 UStG belegt das Wort für die
  Selbstabrechnung, und dieser Beleg kommt hier real vor — die
  Vermittlungsprovision wird genau so abgerechnet. Zwei entgegengesetzte
  Belege hätten denselben Namen getragen, einer umsatzmindernd, einer
  aufwandsbegründend.
- **„Rechnungskorrektur" für die Teilrücknahme.** Marktüblich und dem
  Empfänger vertraut. Verworfen, weil die **Berichtigung** nach §31 Abs. 5
  UStDV kommen soll und „Rechnungskorrektur" neben
  „Rechnungsberichtigung" dieselbe Falle ergibt wie *Leistungsvermittlung*
  neben *Vermittlungsleistung*.
- **Keine Berichtigung bauen**, damit „Rechnungskorrektur" kollisionsfrei
  bleibt. Verworfen, weil der häufigste Korrekturfall — eine fehlende
  USt-IdNr. auf einer bereits bezahlten Rechnung — dann ein Storno kostet:
  drei Belege für einen Tippfehler, und die Zahlung strandet auf dem
  stornierten Beleg, während die Neurechnung offen dasteht.

## Consequences

- Identifier: `Cancellation`, `PartialCancellation`, `Correction`,
  `SelfBilledInvoice`. Das ersetzt `CancellationInvoice` und `CreditNote`
  aus `CLAUDE.md`. `CreditNote` war zusätzlich unscharf: UNTDID 381 deckt
  die vollständige *und* die teilweise Rücknahme ab, trennt also nicht das,
  was der Identifier zu trennen behauptete.
- Der Empfänger liest ein Wort, das er von anderen Werkzeugen nicht kennt.
  Das ist der bezahlte Preis, nicht ein Versehen.
- Die **Berichtigung** ist reserviert, aber nicht gebaut. Bis dahin bleibt
  bei Angabenfehlern nur das **Storno** — auch bei bezahlten Rechnungen.
