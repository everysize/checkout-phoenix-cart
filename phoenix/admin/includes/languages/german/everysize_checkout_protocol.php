<?php
/*
  $Id$

  everysize Checkout — Texte der Protokoll-Seite (everysize_checkout_protocol.php).
  Phoenix lädt diese Datei automatisch mit der Seite.
*/

define('HEADING_TITLE', 'everysize Checkout – Protokoll');
define('TEXT_PROTOCOL_STATE_ALL', 'Alle');
define('TEXT_PROTOCOL_STATE_OK', 'Ok');
define('TEXT_PROTOCOL_STATE_ERROR', 'Fehler');
define('TEXT_PROTOCOL_TIME', 'Zeit');
define('TEXT_PROTOCOL_STATE', 'Status');
define('TEXT_PROTOCOL_TITLE', 'Titel');
define('TEXT_PROTOCOL_MESSAGE', 'Meldung');
define('TEXT_PROTOCOL_EMPTY', 'Keine Einträge.');
define('TEXT_PROTOCOL_COUNT', '%d Einträge (neueste zuerst, maximal 31 Tage).');
define('TEXT_PROTOCOL_NOT_INSTALLED', 'Das Add-on ist nicht installiert — es gibt kein Protokoll.');

// Übersetzungen der Protokoll-Einträge (title_key aus Schema 2, siehe
// PhoenixProtocol::classifyTitle/classifyMessage). Die Parameter sind
// Bestell-/everysize-Nummern und Zähler; API-Fehlerdetails stehen im Text.
define('TEXT_PROTOCOL_SYNC_IMPORT_SUMMARY', 'Importiert: %1$d von %2$d Bestellungen aus everysize');
define('TEXT_PROTOCOL_SYNC_IMPORT_FAILED', 'Import aus everysize fehlgeschlagen');
define('TEXT_PROTOCOL_SYNC_EXPORT_SUMMARY', 'Gemeldet an everysize: %1$d versendet, %2$d Sendungsnummern nachgetragen, %3$d storniert, %4$d Retouren, %5$d automatisch erstattet, %6$d übersprungen (bei everysize widerrufen)');
define('TEXT_PROTOCOL_SYNC_EXPORT_FAILED', 'Meldung an everysize fehlgeschlagen');
define('TEXT_PROTOCOL_EXPORT_AUTOREFUNDMANUAL', 'Bestellung %1$s (%2$s): automatische Erstattung nach Storno fehlgeschlagen — bitte in der everysize-Box der Bestellung erstatten: %3$s');
define('TEXT_PROTOCOL_EXPORT_AUTOREFUNDRETRY', 'Bestellung %1$s (%2$s): automatische Erstattung nach Storno fehlgeschlagen (wird beim nächsten Abgleich erneut versucht): %3$s');
define('TEXT_PROTOCOL_EXPORT_REVOKEDSKIPPED', 'Bestellung %1$s (%2$s) ist bei everysize widerrufen — Versand nicht gemeldet');
define('TEXT_PROTOCOL_EXPORT_TRACKINGSKIPPED', 'Bestellung %1$s (%2$s) ist %3$s bei everysize — Sendungsnummer %4$s nicht gemeldet');
define('TEXT_PROTOCOL_EXPORT_TRACKINGFAILED', 'Bestellung %1$s (%2$s), Sendungsnummer %3$s: %4$s');
define('TEXT_PROTOCOL_EXPORT_REVOCATIONFAILED', 'Bestellung %1$s (%2$s), %3$s: %4$s');
define('TEXT_PROTOCOL_EXPORT_ORDERFAILED', 'Bestellung %1$s (%2$s): %3$s');
define('TEXT_PROTOCOL_IMPORT_ORDERFAILED', '%1$s: %2$s');
define('TEXT_PROTOCOL_IMPORT_NOORDERID', 'everysize-Bestellung ohne everysizeOrderId übersprungen');
