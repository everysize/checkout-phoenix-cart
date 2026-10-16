<?php

/*
  $Id$

  everysize Checkout — Texte der Bestelldetail-Box (Hook listen_orderTab)
  und der everysize-Suche in der Bestellübersicht. Wird vom Hook geladen
  (nicht von Phoenix, daher Rückgabe-Array statt Konstanten).
*/

return [
    'nav_title' => '<i class="fas fa-sync-alt fa-fw me-1"></i>everysize',

    // Box-Abschnitte
    'section_order'   => 'everysize-Bestellung',
    'section_refund'  => 'Erstattung über everysize',
    'section_reports' => 'Rückmeldungen an everysize',
    'section_tracking' => 'Sendung',

    // Infotabelle
    'es_order_number'    => 'everysize-Bestellnummer',
    'imported'           => 'Importiert',
    'number_reported'    => 'Bestellnummer an everysize gemeldet',
    'not_yet'            => 'noch nicht',
    'paypal_capture'     => 'PayPal-Transaktion (Capture-ID)',
    'original_email'     => 'Original-E-Mail der Kundin',
    'original_email_hint' => '(nur zur Information – Mails des Shops gehen an die everysize-Weiterleitungsadresse der Bestellung)',

    // Sendung
    'tracking_code'   => 'Sendungsnummer',
    'tracking_carrier' => 'Carrier',
    'tracking_save'   => 'Sendungsnummer speichern',
    'tracking_saved'  => 'Sendungsnummer gespeichert.',
    'tracking_hint'   => 'Wird beim nächsten Abgleich an everysize gemeldet (Status „versendet“).',

    // Erstattung
    'paid'            => 'Bezahlt',
    'refunded'        => 'erstattet',
    'open'            => 'noch offen',
    'date'            => 'Datum',
    'refund_amount'   => 'Erstatteter Betrag',
    'refund_status'   => 'Status',
    'refund_id'       => 'Erstattungs-ID',
    'amount'          => 'Betrag (EUR)',
    'refund_button'   => 'Erstatten',
    'refund_confirm'  => 'Erstattung von %s € über everysize an die Kundin? Das kann nicht zurückgenommen werden.',
    'hint_refund'     => 'Die Erstattung läuft über everysize: der Betrag geht über das PayPal-Konto zurück, mit dem die Kundin bezahlt hat. Kein PayPal-Zugang im Shop.',
    'hint_autorefund' => 'Ein Storno der Bestellung vor dem Versand erstattet das Modul automatisch voll (Einstellung auf der everysize-Seite), solange noch nichts erstattet wurde.',
    'refunds_error'   => 'Erstattungen konnten nicht geladen werden:',
    'refunds_none'    => 'Noch keine Erstattungen.',
    'no_credentials'  => 'Keine Zugangsdaten vorhanden — die Erstattung braucht die Verbindung zu everysize (Einstellungen auf der everysize-Seite).',
    'not_installed'   => 'Das Add-on ist nicht installiert.',
    'refund_failed'   => 'Erstattung fehlgeschlagen:',
    'refund_not_possible' => 'Für diese Bestellung ist keine Erstattung über everysize möglich.',
    'refund_amount_rejected' => 'everysize hat den Betrag abgelehnt, z. B. weil er höher ist als der noch nicht erstattete Betrag.',
    'refund_paypal_rejected' => 'PayPal hat die Erstattung abgelehnt.',
    'refund_invalid'  => 'Bitte einen gültigen Betrag größer 0 angeben.',
    'return_checkbox' => 'Ware ist zurückgekommen: Retoure an everysize melden',
    'return_button'   => 'Retoure melden',
    'return_confirm'  => 'Retoure an everysize melden? Das kann nicht zurückgenommen werden.',
    'return_done'     => 'Retoure an everysize gemeldet.',
    'return_skipped'  => 'Die Bestellung ist bei everysize bereits storniert, die Retoure wurde nicht gemeldet.',
    'return_not_shipped' => 'Eine Retoure setzt einen gemeldeten Versand voraus — die Bestellung zuerst in den Status „versendet“ setzen.',
    'hint_return'     => 'Bei einer Retoure das Häkchen setzen und den zu erstattenden Betrag eintragen — z. B. ohne Versandkosten. Gemeldet wird zuerst die Retoure, dann wird der Betrag erstattet.',

    // Rückmeldungen
    'report_none'      => 'Noch keine Rückmeldungen.',
    'report_time'      => 'Zeit',
    'report_kind'      => 'Meldung',
    'report_item'      => 'Artikel / Sendungsnummer / Erstattung',
    'report_reason'    => 'Grund / Betrag',
    'report_result'    => 'Ergebnis',
    'kind_fulfillment' => 'Versand',
    'kind_tracking'    => 'Sendungsnummer',
    'kind_revocation'  => 'Storno',
    'kind_return'      => 'Retoure',
    'kind_refund'      => 'Rückerstattung',
    'result_sent'      => 'gemeldet',
    'result_known'     => 'bekannt',
    'result_skipped'   => 'übersprungen',
    'auto_refund_sku'  => 'automatisch, Storno vor Versand',
    'reason_merchant_decline' => 'Händler storniert',

    // Bestellübersicht
    'overview_search' => 'everysize-Nummer',
    'overview_search_active' => 'Filter: everysize-Nummer „%s“',
    'overview_search_reset' => 'Filter zurücksetzen',
];
