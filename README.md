# everysize Checkout für CE Phoenix Cart (Add-on 1.0.0)

Bindet einen CE-Phoenix-Cart-Shop an den everysize-Direktkauf an: Bestellungen
aus everysize werden importiert (inkl. Gastbestellungen, Varianten,
Bestandsabzug und optionaler Bestellbestätigung), und Bestellnummer, Versand,
Sendungsnummern, Storno, Retoure und Auto-Erstattungen werden zurückgemeldet.

Phoenix-Add-ons liegen verteilt im Shop-Root (`admin/`, `includes/hooks/`,
`ext/everysize_checkout/`, Sprachdateien) — es gibt kein Module-Center, das
ein ZIP entgegennimmt; die Installation ist das Kopieren der Dateien
(siehe [Installation](#installation)).

## Voraussetzungen

- CE Phoenix Cart 1.1 (getestet mit 1.1.0.8)
- PHP ≥ 7.4 mit `curl` und `json` (getestet mit 8.3)
- MySQL 5.7/8 (das Add-on legt vier eigene Tabellen `everysize_checkout_*` an)
- Ein Cron, der den Abgleich aufruft (siehe unten)

Kein Zahlungsmodul nötig: bezahlt wird bei everysize. Erstattet wird über
everysize (das Geld geht über das PayPal-Konto zurück, mit dem bezahlt wurde).
Phoenix kennt keinen Online-Erstattungsweg für Zahlungsarten — die Erstattung
läuft über die everysize-Box im Bestelldetail und die Auto-Erstattung.

## Installation

1. ZIP (`everysize-checkout-phoenix-cart-<version>.zip`, Download in everysize
   Business unter „Shop-Integration" → Phoenix Cart) entpacken und den Inhalt
   in das **Shop-Root** kopieren (per FTP/SFTP) — es landen `admin/`,
   `includes/` und `ext/everysize_checkout/` dazu; Phoenix-eigene Dateien
   werden nicht berührt.
   **Hinweis zum Admin-Ordner:** Phoenix empfiehlt, den Standard-`admin`-Ordner
   umzubenennen (Sicherheit). Liegt euer Admin-Panel unter anderem Namen,
   kopiert den Inhalt des ZIP-`admin/`-Ordners entsprechend in **euren
   (ggf. umbenannten) Admin-Ordner** statt nach `admin/` — `includes/` und
   `ext/` immer ins Shop-Root.
2. **Cache leeren**: im Shop-Root die Dateien `includes/work/*.cache`
   löschen (Phoenix hängt Hook-Dateien und Sprachen an Dateipfade).
3. **Installieren**: Admin → Menü **everysize → everysize Checkout** →
   „Installieren". Das legt die Tabellen `everysize_checkout_settings`,
   `everysize_checkout_order`, `everysize_checkout_order_item`,
   `everysize_checkout_report` und `everysize_checkout_protocol` an
   (idempotent) und setzt die Schema-Version. **Deinstallieren behält alle
   Daten** — es setzt nur das Install-Flag zurück, damit sich die
   Installation später fortsetzen kann.
4. Client-ID und Client-Secret (aus everysize Business, „Shop-Integration")
   eintragen und speichern.
5. Konfiguration: Carrier, Import-Status, Status für Versand/Storno/Retoure,
   Bestellbestätigung an/aus, Auto-Erstattung an/aus, „Abgleich aktiv".

**Update:** Neues ZIP entpacken und den Inhalt über den bestehenden Shop-Root
kopieren (Dateien werden ersetzt, Zugangsdaten, Einstellungen und Verknüpfungen
bleiben in der Datenbank), `includes/work/*.cache` löschen. Steht eine neue
Schema-Version an, hebt der nächste Klick auf „Installieren" die Tabellen
idempotent an — das Add-on bleibt dabei installiert und aktiv.

## Abgleich (Import + Rückmeldung)

Der Abgleich läuft über einen System-Cron alle 5 Minuten, CLI-Einstieg:

```
php /pfad/zum/shop/ext/everysize_checkout/sync.php            # normal
php ext/everysize_checkout/sync.php --json                    # für Maschinen
php ext/everysize_checkout/sync.php --force                   # umgeht „Abgleich aktiv“
```

Der Abgleich importiert neue Bestellungen („processing"), meldet Status und
Sendungsnummern zurück und räumt das Protokoll auf (31 Tage). Ohne
Zugangsdaten beendet er sich mit Exit 0 und einer Meldung; bei Fehlern mit
Exit 1.

**API-Umgebung:** ohne Konfiguration läuft das Add-on immer gegen die
Produktion (`https://ext-checkout.everysize.com`). Ein Test-Shop setzt
`EVERYSIZE_CHECKOUT_API_URL` auf die Adresse eines Test-Systems — die
 everysize mit den Test-Zugangsdaten ausgibt. Jede andere Adresse (nicht
`https://…everysize.com`) ist ein Fehler und fällt **nicht** auf Produktion
zurück.

## Was der Import anlegt

- Eine `orders`-Zeile wie der Phoenix-Checkout (Denormalisate
  `customers_*`/`delivery_*`/`billing_*`, `payment_method` als Text „everysize
  Checkout", Versandart aus `orders_total`), Gastbestellung mit
  `customers_id = 0` — Phoenix kennt keinen Gast-Checkout, aber die Spalte
  hat keinen Fremdschlüssel, und der Admin liest ausschließlich die
  Denormalisate.
- `orders_products` mit dem **Netto-Stückpreis** (Phoenix speichert netto),
  `products_tax` = Mehrwertsteuersatz; die Variante als `products_attributes`-
  Zeile (Option „Größe“, Aufpreis 0.0000). Die Artikelnummer löst sich auf
  nach `products_model` — exakt vor groß/klein-insensitiv, aktive vor
  inaktiven Produkten; passt kein Modell, wird „Basis-Modell + Größe“ als
  Optionswert probiert. Mehrdeutige Artikelnummern sind ein Fehler, der
  Bestand wird nie geprüft (die Bestellung ist bezahlt).
- `orders_total` mit Phoenix-Titeln: Positionen und Versand **netto** und die
  MwSt.-Zeile mit dem steuerlichen Satz je Position (Logik wie `Tax::fetch`:
  Geo-Zone des Shops, mehrere Steuersätze je Position als eigene Zeilen),
  Gesamtsumme fett. Steht `DISPLAY_PRICE_WITH_TAX` auf true, werden Positionen
  und Versand brutto angezeigt und die MwSt.-Zeile entfällt.
- Bestandsabzug nach der Phoenix-Matrix: `STOCK_LIMITED === 'true'` zieht
  `products_quantity` ab und setzt das Produkt bei 0 optional inaktiv
  (dasselbe wie `update_stock.php`). Der Attribut-Bestand wird nicht
  angetastet — Phoenix hat keinen `ATTRIBUTES_STOCK`.
- Eine stornierte Bestellung (`REVOKED`) wird direkt in den konfigurierten
  Storno-Status gestellt und bucht keinen Bestand ab.

## Rückmeldung an everysize

- Versand: Bestellung auf den konfigurierten Versand-Status setzen — der
  Sync meldet Fulfillment (Carrier aus der Einstellung). Sendungsnummer und
  Carrier tragen Händler in der everysize-Box ein, auch nachträglich oder
  korrigiert (bis 14 Tage nachgemeldet, solange die Bestellung im Checkout
  `completed` ist).
- Storno: Statuswechsel auf den Storno-Status → `MERCHANT_DECLINE`. Ist die
  „Automatische Erstattung" aktiv und es existiert kein Erstattungsvorgang,
  wird voll erstattet. Nach einem gemeldeten Versand wird derselbe Status-
  wechsel als `RETOUR` gemeldet — auch wenn Versand und Storno im selben
  5-Minuten-Fenster liegen (erst Fulfillment mit Sendungsnummer, dann
  `RETOUR`).
- Retoure: Kommt Ware zurück, erstatten Sie wie gewohnt — bei
  everysize-Bestellungen in der everysize-Box: Häkchen „Ware ist
  zurückgekommen: Retoure an everysize melden“ setzen, den zu erstattenden
  Betrag eintragen (z. B. ohne Versandkosten) und „Erstatten“. Das Add-on
  meldet zuerst die Retoure (`RETOUR`) und erstattet dann den Betrag. Ist
  schon alles erstattet, meldet „Retoure melden“ die Retoure allein. Wer
  lieber mit Status arbeitet, nutzt weiter den Retoure-Status bzw. den
  Storno-Status nach dem Versand (beides ohne Erstattung).
- Erstattungen aus der Box laufen sofort über den Checkout an PayPal (ganz
  oder teilweise); Auto-Erstattungen nach dem Storno ebenfalls. Beide stehen
  als Kommentar im Bestellverlauf, Box-Erstattungen zusätzlich im Protokoll.
- Jede Rückmeldung wird idempotent vermerkt (`everysize_checkout_report`),
  so dass ein Abgleich-Lauf nichts doppelt sendet.
- Die Händler-Bestellnummer meldet der Import NACH dem Commit zurück; eine
  fehlgeschlagene Rückmeldung holt der nächste Lauf nach.

## Admin

- Menü **everysize**:
  - **everysize Checkout** — Installation, Zugangsdaten (Secret wird nie
    zurückgeschrieben), Verbindungstest, Status-Zuordnungen, Bestell-
    bestätigung, Auto-Erstattung, „Jetzt abgleichen" (läuft synchron im
    Request).
  - **everysize Protokoll** — die Einträge des Abgleichs, gefiltert nach
    ok/Fehler, 50 pro Seite.
- Bestelldetail: Tab **everysize** mit everysize-Nummer, Import-Zeit,
  Zahlungsmeldung, PayPal-Capture-ID, Original-Mail der Kundin, Zahlständen
  (bezahlt/erstattet/noch offen), Erstattungs-Formular (mit Bestätigungs-
  dialog und einmaligem Erstattungsschlüssel), Sendungsnummer + Carrier und
  der Rückmeldungs-Tabelle. Ohne Zugangsdaten zeigt die Box einen Hinweis
  statt der Zahlstände; der Rest bleibt nutzbar.
- Bestellübersicht: Kennzeichen „— everysize <Nummer>" hinter der Gesamtsumme
  und ein Suchfeld für die everysize-Nummer (eine eigene Spalte gibt es
  nicht — Phoenix' Listenansicht ist hart kodiert).

## Artikelnummern

Everysize bestellt mit der Varianten-Artikelnummer (`CW2288-111-42.5`), die
der Händler im everysize-Business hinterlegt. Sie muss im Shop auffindbar
sein — entweder als `products_model` exakt in dieser Form, oder als
Kombination aus `products_model` (Basis) und dem Optionswert der Größe
(„42.5"). Mehrere Produkte mit derselben Artikelnummer sind ein Importfehler
(wird im Protokoll vermerkt), damit nie in die falsche Bestellung importiert
wird.

## Lizenz

MIT, siehe `LICENSE`.
