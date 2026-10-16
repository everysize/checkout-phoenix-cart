# Changelog

Alle wesentlichen Änderungen an diesem Projekt werden in dieser Datei
dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
die Versionen folgen [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0

- Bestellimport aus dem everysize-Checkout in CE Phoenix Cart 1.1
  (Gastbestellung, Positionen mit dem Netto-Stückpreis, den Phoenix speichert,
  Größen als Attribute ohne Aufpreis, `orders_total` im Phoenix-Layout,
  Bestandsabzug, stornierte Bestellungen direkt in den Storno-Status).
- Bestellnummer, Fulfillment mit Sendungsnummer und Carrier, Storno, Retoure
  und automatische Erstattung zurück an everysize gemeldet (idempotent,
  Sendungsnummern bis 14 Tage nachkorrigiert).
- Erstattungsformular in der Bestellbox (ganz oder teilweise, über den
  Checkout an PayPal) mit einmaligem Erstattungsschlüssel und bekannten
  Fehlerursachen in Klartext; das Häkchen „Ware ist zurückgekommen“ meldet
  zuerst die Retoure und erstattet dann den eingetragenen Betrag (z. B. ohne
  Versand), oder meldet die Retoure allein.
- Admin-Seiten: Einstellungen (Zugangsdaten werden nie zurückgeschrieben,
  Verbindungstest, Status-Zuordnungen, Bestellbestätigung, Auto-Erstattung,
  manueller Abgleich) und Modul-Protokoll (31 Tage, Filter, 50 pro Seite).
- Bestellübersicht: everysize-Kennzeichen an der Gesamtsumme und Suche nach
  der everysize-Bestellnummer.
- CLI-Einstieg für System-Crons (`--json`, `--force`).
