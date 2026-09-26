# Gutschriften nach §14 Abs. 2 Satz 2 UStG bleiben im Umfang

Spec §2 begrenzt die Anwendung auf „outbound billing only" und schließt
Eingangsrechnungen und Ausgabenerfassung aus. Die **Gutschrift** — die
Abrechnung, die wir als Leistungsempfänger über eine uns erbrachte
**Vermittlungsleistung** ausstellen — nehmen wir trotzdem hinein, weil sie
diese Grenze nicht verletzt: sie wird von uns ausgestellt und von uns
versendet, wie jeder andere Beleg. Was sich umdreht, ist nicht die
Belegrichtung, sondern die **Leistungsrichtung**. Der Anlass ist konkret:
wer uns einen **Kunden** vermittelt, erhält eine einmalige
**Vermittlungsprovision**, und darüber muss abgerechnet werden.

## Consequences

- Die **Gutschrift** zieht ihre **Belegnummer** aus demselben
  **Nummernkreis** wie die **Rechnung**, weil sie eine Rechnung im Sinne des
  §14 UStG ist. Sie ist damit derselbe schwere, serialisierende Vorgang wie
  das Ausstellen einer Rechnung, kein leichter Nebenweg.
- **Der Identitätsblock dreht sich.** Leistender Unternehmer ist der
  **Vermittler**: seine Steuernummer bzw. USt-IdNr. gehört auf den Beleg,
  und weil wir ihn bezahlen, brauchen wir seine Bankverbindung. Der heutige
  `Customer` trägt keines dieser Felder.
- **Ein Zustand kommt hinzu: widersprochen.** Nach §14 Abs. 2 Satz 3 UStG
  verliert die Gutschrift ihre Wirkung, wenn der Empfänger widerspricht.
  Keine **Rechnung** hat diesen Zustand.
- Sie setzt eine **vorherige Vereinbarung** mit dem Vermittler voraus.
- ZUGFeRD: UNTDID-Typ **389** (Self-billed invoice), nicht 380.
- **Auswertungen müssen sie ausschließen.** Ihr Betrag ist Aufwand, ihre
  Umsatzsteuer ist für uns Vorsteuer. Ein Dashboard, das über die
  Belegtabelle summiert, rechnet sonst falsch — und zwar unauffällig, weil
  die Gutschrift nach dem gemeinsamen Nummernkreis maximal wie eine Rechnung
  aussieht.

## Was ausdrücklich draußen bleibt

Eingangsrechnungen. Der Maßstab steht in `CONTEXT.md`: eine Gutschrift ist
das richtige Instrument nur, wenn **allein der Zahlende den Betrag kennt**.
Wir wissen, welche Kunden über einen Vermittler kamen — er nicht. Ein
Subunternehmer kennt dagegen seine Stunden und rechnet selbst ab; das ergibt
eine Eingangsrechnung, und die bleibt außerhalb.
