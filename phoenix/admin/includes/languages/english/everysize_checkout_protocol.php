<?php
/*
  $Id$

  everysize Checkout — texts of the protocol page (everysize_checkout_protocol.php).
  Phoenix loads this file automatically with the page.
*/

define('HEADING_TITLE', 'everysize Checkout – Protocol');
define('TEXT_PROTOCOL_STATE_ALL', 'All');
define('TEXT_PROTOCOL_STATE_OK', 'Ok');
define('TEXT_PROTOCOL_STATE_ERROR', 'Errors');
define('TEXT_PROTOCOL_TIME', 'Time');
define('TEXT_PROTOCOL_STATE', 'State');
define('TEXT_PROTOCOL_TITLE', 'Title');
define('TEXT_PROTOCOL_MESSAGE', 'Message');
define('TEXT_PROTOCOL_EMPTY', 'No entries.');
define('TEXT_PROTOCOL_COUNT', '%d entries (newest first, kept for 31 days).');
define('TEXT_PROTOCOL_NOT_INSTALLED', 'The add-on is not installed — there is no protocol.');

// Translations of the protocol entries (title_key from schema 2, see
// PhoenixProtocol::classifyTitle/classifyMessage).
define('TEXT_PROTOCOL_SYNC_IMPORT_SUMMARY', 'Imported %1$d of %2$d orders from everysize');
define('TEXT_PROTOCOL_SYNC_IMPORT_FAILED', 'Import from everysize failed');
define('TEXT_PROTOCOL_SYNC_EXPORT_SUMMARY', 'Reported to everysize: %1$d shipped, %2$d tracking codes added later, %3$d cancelled, %4$d returned, %5$d refunded automatically, %6$d skipped (revoked at everysize)');
define('TEXT_PROTOCOL_SYNC_EXPORT_FAILED', 'Report to everysize failed');
define('TEXT_PROTOCOL_EXPORT_AUTOREFUNDMANUAL', 'Order %1$s (%2$s): automatic refund after cancellation failed, please refund in the order box "everysize": %3$s');
define('TEXT_PROTOCOL_EXPORT_AUTOREFUNDRETRY', 'Order %1$s (%2$s): automatic refund after cancellation failed (retried next run): %3$s');
define('TEXT_PROTOCOL_EXPORT_REVOKEDSKIPPED', 'Order %1$s (%2$s) is revoked at everysize, shipping not reported');
define('TEXT_PROTOCOL_EXPORT_TRACKINGSKIPPED', 'Order %1$s (%2$s) is %3$s at everysize, tracking code %4$s not reported');
define('TEXT_PROTOCOL_EXPORT_TRACKINGFAILED', 'Order %1$s (%2$s), tracking code %3$s: %4$s');
define('TEXT_PROTOCOL_EXPORT_REVOCATIONFAILED', 'Order %1$s (%2$s), %3$s: %4$s');
define('TEXT_PROTOCOL_EXPORT_ORDERFAILED', 'Order %1$s (%2$s): %3$s');
define('TEXT_PROTOCOL_IMPORT_ORDERFAILED', '%1$s: %2$s');
define('TEXT_PROTOCOL_IMPORT_NOORDERID', 'everysize order without everysizeOrderId skipped');
