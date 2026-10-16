<?php
/*
  $Id$

  everysize Checkout — Admin-Texte (Deutsch). Wird von application_top
  automatisch zur Seite geladen (includes/languages/<language>/<page>.php).
*/

  const HEADING_TITLE = 'everysize Checkout';

  const TEXT_NOT_INSTALLED = 'Das everysize-Checkout-Add-on ist noch nicht installiert. „Installieren“ legt die Modul-Tabellen und Einstellungen an — Shop-Daten werden nicht verändert. Eine spätere Deinstallation behält alle Daten.';
  const TEXT_INSTALL = 'Installieren';
  const TEXT_INSTALL_SUCCESS = 'everysize Checkout installiert. Bitte Zugangsdaten unten eintragen.';
  const TEXT_REMOVE = 'Deinstallieren (Daten bleiben)';
  const TEXT_REMOVE_SUCCESS = 'everysize Checkout deinstalliert. Alle Daten (Zugangsdaten, Bestell-Verknüpfungen, Protokoll) bleiben; der Abgleich pausiert, bis wieder installiert wird.';
  const TEXT_REMOVE_CONFIRM = 'everysize Checkout deinstallieren?\\n\\nAlle Daten (Zugangsdaten, Bestell-Verknüpfungen, Protokoll) bleiben — der Abgleich pausiert nur, bis wieder installiert wird.';

  const TEXT_HEADING_CREDENTIALS = 'Zugangsdaten';
  const TEXT_CLIENT_ID = 'Client-ID';
  const TEXT_CLIENT_SECRET = 'Client-Secret';
  const TEXT_SECRET_STORED = 'gespeichert — leer lassen zum Behalten';
  const TEXT_SECRET_HINT = 'Das Secret wird nie wieder angezeigt. Ein leeres Feld oder ein Platzhalter des Browsers (nur Punkte/Sterne) behält den gespeicherten Wert.';
  const TEXT_REMOVE_CREDENTIALS = 'Zugangsdaten entfernen';
  const TEXT_REMOVE_CREDENTIALS_CONFIRM = 'Gespeicherte Zugangsdaten entfernen?\\n\\nBis neue eingetragen sind, macht das Add-on keine API-Aufrufe.';
  const TEXT_CREDENTIALS_REMOVED = 'Zugangsdaten entfernt.';

  const TEXT_TEST_CONNECTION = 'Verbindung testen';
  const TEXT_PROTOCOL_LINK = 'Protokoll';
  const TEXT_TEST_SUCCESS = 'Verbindung OK — Shop-ID %d bei %s.';
  const TEXT_TEST_FAILED = 'Verbindung fehlgeschlagen';
  const TEXT_TEST_MISSING = 'Keine Zugangsdaten zum Testen (eintragen oder vorher speichern).';

  const TEXT_HEADING_IMPORT = 'Import';
  const TEXT_IMPORT_STATUS = 'Bestellstatus für neue Bestellungen';
  const TEXT_SEND_ORDER_CONFIRMATION = 'Bestellbestätigung an den Kunden';
  const TEXT_SEND_ORDER_CONFIRMATION_HINT = 'Standard „Nein“: Der Kunde hat die Bestellbestätigung bereits von everysize. „Ja“ schickt nach erfolgreichem Import genau eine Bestellbestätigung über den Shop.';

  const TEXT_HEADING_EXPORT = 'Export an everysize';
  const TEXT_SHIPPING_STATUSES = 'Bestellstatus „versendet“';
  const TEXT_SHIPPING_STATUSES_HINT = 'Eine Bestellung, die in einem dieser Status war, meldet den Versand (Carrier + Sendungsnummer) an everysize.';
  const TEXT_CANCELLATION_STATUSES = 'Bestellstatus „storniert“';
  const TEXT_RETURN_STATUSES = 'Bestellstatus „Retoure“ (optional)';
  const TEXT_RETURN_STATUSES_HINT = 'Stornos nach gemeldetem Versand werden als Retoure (RETOUR) gemeldet; ohne Retoure-Status werden nur Stornos gemeldet.';
  const TEXT_CARRIER = 'Standard-Carrier';
  const TEXT_CARRIER_HINT = 'Wird gemeldet, wenn zur Sendungsnummer kein Carrier zugeordnet werden kann.';

  const TEXT_HEADING_REFUND = 'Erstattungen';
  const TEXT_AUTO_REFUND = 'Automatische Erstattung bei Storno vor Versand';
  const TEXT_AUTO_REFUND_HINT = 'Wird eine bezahlte Bestellung vor dem Versand storniert, erstattet everysize automatisch den vollen Betrag (nur wenn noch keine Erstattung existiert).';
  const TEXT_AUTO_REFUND_SINCE = 'ab %s';

  const TEXT_HEADING_SYNC = 'Abgleich';
  const TEXT_SYNC_ENABLED = 'Automatischer Abgleich';
  const TEXT_SYNC_ENABLED_HINT = 'Führt den Abgleich (Import + Export) alle 5 Minuten über den System-Cron bzw. den Shop-Fallback aus. „Jetzt abgleichen“ ist auf dieser Seite.';
  const TEXT_SYNC_TRIGGER_HINT = 'Auslöser: Cronjob (CLI „php ext/everysize_checkout/sync.php“ alle 5 Minuten) oder der Button „Jetzt abgleichen“ auf dieser Seite. Ohne Zugangsdaten tut der Abgleich nichts.';
  const TEXT_SYNC_NOW = 'Jetzt abgleichen';
  const TEXT_SYNC_DONE = 'Abgleich beendet:';
  const TEXT_SYNC_FAILED = 'Abgleich fehlgeschlagen:';

  const TEXT_SAVE = 'Speichern';
  const TEXT_SAVE_SUCCESS = 'Einstellungen gespeichert.';
  const TEXT_YES = 'Ja';
  const TEXT_NO = 'Nein';

  const TEXT_ENVIRONMENT = 'Umgebung';
  const TEXT_ENV_PRODUCTION = 'PRODUKTION';
  const TEXT_ENV_STAGE = 'Testumgebung';
  const TEXT_ENV_INVALID = 'ungültig';
  const TEXT_ENV_INVALID_HINT = 'Die konfigurierte everysize-API-URL ist ungültig — das Add-on macht keine API-Aufrufe (kein stiller Fallback zur Produktion). EVERYSIZE_CHECKOUT_API_URL prüfen.';

  const TEXT_MODULE_VERSION = 'Version';
  const TEXT_HEADING_DANGER = 'Kritischer Bereich';
