<?php
/*
  $Id$

  everysize Checkout — Admin-Texte (English). Wird von application_top
  automatisch zur Seite geladen (includes/languages/<language>/<page>.php).
*/

  const HEADING_TITLE = 'everysize Checkout';

  const TEXT_NOT_INSTALLED = 'The everysize Checkout add-on is not installed yet. "Install" creates the module tables and the settings — shop data is never changed. Uninstalling later keeps all data.';
  const TEXT_INSTALL = 'Install';
  const TEXT_INSTALL_SUCCESS = 'everysize Checkout installed. Enter your credentials below.';
  const TEXT_REMOVE = 'Uninstall (data is kept)';
  const TEXT_REMOVE_SUCCESS = 'everysize Checkout uninstalled. All data (credentials, order links, protocol) is kept; the sync pauses until it is installed again.';
  const TEXT_REMOVE_CONFIRM = 'Uninstall everysize Checkout?\\n\\nAll data (credentials, order links, protocol) is kept — the sync just pauses until it is installed again.';

  const TEXT_HEADING_CREDENTIALS = 'Credentials (External API)';
  const TEXT_CLIENT_ID = 'Client ID';
  const TEXT_CLIENT_SECRET = 'Client Secret';
  const TEXT_SECRET_STORED = 'stored — leave empty to keep';
  const TEXT_SECRET_HINT = 'The secret is never displayed again. An empty field or a placeholder of the browser (only bullets/asterisks) keeps the stored value.';
  const TEXT_REMOVE_CREDENTIALS = 'Remove credentials';
  const TEXT_REMOVE_CREDENTIALS_CONFIRM = 'Remove the stored credentials?\\n\\nUntil new ones are entered the add-on makes no API calls.';
  const TEXT_CREDENTIALS_REMOVED = 'Credentials removed.';

  const TEXT_TEST_CONNECTION = 'Test connection';
  const TEXT_PROTOCOL_LINK = 'Protocol';
  const TEXT_TEST_SUCCESS = 'Connection OK — shop ID %d at %s.';
  const TEXT_TEST_FAILED = 'Connection failed';
  const TEXT_TEST_MISSING = 'No credentials to test (enter them or save them first).';

  const TEXT_HEADING_IMPORT = 'Import';
  const TEXT_IMPORT_STATUS = 'Order status for new orders';
  const TEXT_SEND_ORDER_CONFIRMATION = 'Order confirmation to the customer';
  const TEXT_SEND_ORDER_CONFIRMATION_HINT = 'Default "No": the customer already got the everysize confirmation. "Yes" sends one order confirmation via the shop after a successful import.';

  const TEXT_HEADING_EXPORT = 'Export to everysize';
  const TEXT_SHIPPING_STATUSES = 'Order status "shipped"';
  const TEXT_SHIPPING_STATUSES_HINT = 'An order that was in one of these statuses reports its fulfillment (carrier + tracking code) to everysize.';
  const TEXT_CANCELLATION_STATUSES = 'Order status "cancelled"';
  const TEXT_RETURN_STATUSES = 'Order status "returned" (optional)';
  const TEXT_RETURN_STATUSES_HINT = 'Cancellations after a reported shipping are reported as return (RETOUR); without a returned status only declines are reported.';
  const TEXT_CARRIER = 'Default carrier';
  const TEXT_CARRIER_HINT = 'Reported when the tracking code has no mapped carrier.';

  const TEXT_HEADING_REFUND = 'Refunds';
  const TEXT_AUTO_REFUND = 'Automatic refund on cancellation before shipping';
  const TEXT_AUTO_REFUND_HINT = 'If a paid order is cancelled before it was shipped, everysize refunds the full amount automatically (only if no refund exists yet).';
  const TEXT_AUTO_REFUND_SINCE = 'from %s';

  const TEXT_HEADING_SYNC = 'Synchronisation';
  const TEXT_SYNC_ENABLED = 'Automatic sync';
  const TEXT_SYNC_ENABLED_HINT = 'Runs the sync (import + export) every 5 minutes via the system cron or the shop fallback trigger. "Sync now" is on this page.';
  const TEXT_SYNC_TRIGGER_HINT = 'Trigger: cron job (CLI "php ext/everysize_checkout/sync.php" every 5 minutes) or the "Sync now" button on this page. Without credentials the sync does nothing.';
  const TEXT_SYNC_NOW = 'Sync now';
  const TEXT_SYNC_DONE = 'Sync finished:';
  const TEXT_SYNC_FAILED = 'Sync failed:';

  const TEXT_SAVE = 'Save';
  const TEXT_SAVE_SUCCESS = 'Settings saved.';
  const TEXT_YES = 'Yes';
  const TEXT_NO = 'No';

  const TEXT_ENVIRONMENT = 'Environment';
  const TEXT_ENV_PRODUCTION = 'PRODUCTION';
  const TEXT_ENV_STAGE = 'Test environment';
  const TEXT_ENV_INVALID = 'invalid';
  const TEXT_ENV_INVALID_HINT = 'The configured everysize API URL is invalid — the add-on makes no API calls (no silent fallback to production). Check EVERYSIZE_CHECKOUT_API_URL.';

  const TEXT_MODULE_VERSION = 'Version';
  const TEXT_HEADING_DANGER = 'Danger zone';
