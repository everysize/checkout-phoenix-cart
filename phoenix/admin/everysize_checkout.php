<?php
/*
  $Id$

  everysize Checkout für Phoenix Cart — Einstellungen und Installation.

  Der Weg, den Phoenix für Add-ons vorsieht, ist "Dateien in den Shop-Root,
  danach im Admin aktivieren": hier ist die eigene Admin-Seite, die das
  Add-on installiert (Tabellen, Schema-Version, Kennzeichen), wieder
  entfernt (Daten bleiben) und die Einstellungen verwaltet. Der
  Menü-Eintrag liegt in admin/includes/boxes/everysize_checkout.php.

  Alle Aktionen laufen per POST mit Form-Token (Form::validate_action_is,
  wie die Phoenix-Admin-Formulare); Zugangsdaten erscheinen nie im HTML —
  das Secret ist ein leeres Passwortfeld ("gespeichert"), ein leeres oder
  nur-maskiertes Absenden behält den gespeicherten Wert (B4).

  Select/Input/Form stammen aus dem Phoenix-Kern (Bootstrap-Look), die
  Status-Multiselects und Radios sind bewusst rohes HTML — der Phoenix-
  Select kann nur eine Vorauswahl.
*/

  $always_valid_actions = [];
  require 'includes/application_top.php';

  require DIR_FS_CATALOG . 'ext/everysize_checkout/autoload.php';

  use Everysize\Checkout\Api\ApiUrl;
  use Everysize\Checkout\Api\ApiException;
  use Everysize\Checkout\Api\Client;
  use Everysize\Checkout\ModuleInfo;
  use Everysize\Checkout\OrderLinks;
  use Everysize\Checkout\Phoenix\Installer;
  use Everysize\Checkout\Phoenix\OrderBox;
  use Everysize\Checkout\Phoenix\PhoenixDb;
  use Everysize\Checkout\Phoenix\PhoenixFactory;
  use Everysize\Checkout\Phoenix\PhoenixProtocol;
  use Everysize\Checkout\Export\StatusExporter;
  use Everysize\Checkout\Phoenix\PhoenixSettings;
  use Everysize\Checkout\StatusList;

  $db_connection = PhoenixDb::fromShopDefines();
  $settings = new PhoenixSettings($db_connection);
  $installer = new Installer($db_connection);
  $installed = $installer->isInstalled();

  $page_message = ''; // Inline-Hinweis, wenn kein Redirect folgt (Verbindungstest)

// --- Aktionen (POST + Token) ----------------------------------------------

  $action = $_GET['action'] ?? '';
  if ($action !== '' && !\in_array($action, $always_valid_actions, true) && !Form::validate_action_is($action)) {
    $action = '';
  }

  // Die JSON-Endpunkte der Bestellbox antworten auch bei abgelehntem Token
  // als JSON (403), damit das AJAX in der Box eine saubere Meldung zeigt.
  $ajax_actions = ['orderRefund', 'orderTracking'];
  if (\in_array($_GET['action'] ?? '', $ajax_actions, true) && !\in_array($action, $ajax_actions, true)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'access denied']);
    exit;
  }

  $ajax_json = static function (array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
  };

  switch ($action) {
    case 'install':
      $installer->install();
      $settings = new PhoenixSettings($db_connection); // Flag-Cache verwerfen
      $installed = true;
      $messageStack->add_session(TEXT_INSTALL_SUCCESS, 'success');
      Href::redirect($Admin->link('everysize_checkout.php'));
      break;

    case 'remove':
      // Deinstallieren behält Daten: Tabellen, Zugangsdaten, Verknüpfungen,
      // Protokoll — wie in den anderen everysize-Add-ons. Nur das Kennzeichen
      // geht, der Abgleich pausiert, bis wieder installiert wird.
      $installer->remove();
      $installed = false;
      $messageStack->add_session(TEXT_REMOVE_SUCCESS, 'success');
      Href::redirect($Admin->link('everysize_checkout.php'));
      break;

    case 'removeCredentials':
      $settings->delete('clientId');
      $settings->delete('clientSecret');
      $messageStack->add_session(TEXT_CREDENTIALS_REMOVED, 'success');
      Href::redirect($Admin->link('everysize_checkout.php'));
      break;

    case 'sync':
      // "Jetzt abgleichen": derselbe Lauf wie der Cron (Import, Export,
      // Protokoll-Aufräumen) — synchron im Request, Ergebnis als Meldung.
      try {
        $result = PhoenixFactory::sync($db_connection)->run();
        $summary = implode(' · ', $result['messages']);
        if ($result['errors'] !== []) {
          // Der messageStack rendert roh — SKUs und API-Meldungen escapen.
          $messageStack->add_session(TEXT_SYNC_FAILED.' '.htmlspecialchars($summary).' '.htmlspecialchars(implode(' | ', $result['errors'])), 'error');
        } else {
          $messageStack->add_session(TEXT_SYNC_DONE.' '.htmlspecialchars($summary), 'success');
        }
      } catch (\Throwable $e) {
        $messageStack->add_session(TEXT_SYNC_FAILED.' '.htmlspecialchars($e->getMessage()), 'error');
      }
      Href::redirect($Admin->link('everysize_checkout.php'));
      break;

    // --- JSON-Endpunkte der Bestellbox (AJAX, kein Formular im Formular) ------
    // orderRefund: Betrag → everysize refund (der frische Idempotency-Key des
    // Seitenaufrufs macht einen Doppelsubmit zu genau einer Erstattung);
    // orderTracking: Sendungsnummer + Carrier speichern (gemeldet wird beim
    // nächsten Abgleich). Antwort immer JSON; bei Erfolg lädt die Box neu.

    case 'orderRefund':
      $boxTexts = OrderBox::loadTexts(OrderBox::languageDirectory($db_connection, (int) ($_SESSION['languages_id'] ?? 0)));
      if (!$installed) {
        $ajax_json(['ok' => false, 'message' => $boxTexts['not_installed'] ?? 'not installed']);
      }
      $refundOrderId = (string) (int) ($_POST['order_id'] ?? 0);
      $refundAmount = str_replace([',', ' '], ['.', ''], trim((string) ($_POST['refund_amount'] ?? '')));
      // Beträge im Protokoll wie im Bestellverlauf: 119,99
      $protocolAmount = static fn (string $amount): string => is_numeric($amount) ? number_format((float) $amount, 2, ',', '.') : $amount;
      $refundKey = trim((string) ($_POST['refund_key'] ?? ''));
      // report_return = 1: die Ware ist zurück — zuerst die Retoure melden
      // (eine volle Erstattung kann die Bestellung bei everysize stornieren,
      // danach nimmt sie keine Retoure mehr an), dann erstatten. refund = 0:
      // nur die Retoure (alles schon erstattet).
      $reportReturn = ($_POST['report_return'] ?? '') === '1';
      $doRefund = ($_POST['refund'] ?? '1') !== '0';
      if (!$doRefund && !$reportReturn) {
        $ajax_json(['ok' => false, 'message' => 'nothing to do']);
      }
      if ($doRefund && (!\preg_match('/^\\d+(\\.\\d{1,2})?$/', $refundAmount) || (float) $refundAmount <= 0.0 || $refundKey === '')) {
        $ajax_json(['ok' => false, 'message' => $boxTexts['refund_invalid'] ?? 'invalid amount']);
      }
      $links = new OrderLinks($db_connection, $settings);
      $refundLink = $links->getLink($refundOrderId);
      if ($refundLink === null || (string) $refundLink['PAYPAL_CAPTURE_ID'] === '') {
        $ajax_json(['ok' => false, 'message' => $boxTexts['refund_not_possible'] ?? 'not refundable']);
      }
      $refundEverysizeOrderId = (string) $refundLink['EVERYSIZE_ORDER_ID'];
      $protocol = new PhoenixProtocol($db_connection);
      $returnMessage = '';
      if ($reportReturn) {
        if (!$links->hasSentFulfillment($refundOrderId)) {
          $ajax_json(['ok' => false, 'message' => $boxTexts['return_not_shipped'] ?? 'not shipped']);
        }
        try {
          $returned = (new StatusExporter(PhoenixFactory::client($settings), $settings, $links))->reportReturn($refundOrderId, $refundEverysizeOrderId);
        } catch (\Throwable $e) {
          $protocol->add(
              'Retoure im Bestelldetail fehlgeschlagen',
              sprintf('Bestellung %s (%s): %s', $refundOrderId, $refundEverysizeOrderId, $e->getMessage()),
              false
          );
          $ajax_json(['ok' => false, 'message' => ($boxTexts['refund_failed'] ?? 'Failed').' ('.$e->getMessage().')']);
        }
        $returnMessage = $returned['skipped'] > 0 ? ($boxTexts['return_skipped'] ?? 'skipped') : ($boxTexts['return_done'] ?? 'return reported');
        $protocol->add(
            'Retoure im Bestelldetail',
            $returned['skipped'] > 0
                ? sprintf('Bestellung %s (%s) ist bei everysize bereits storniert, Retoure nicht gemeldet.', $refundOrderId, $refundEverysizeOrderId)
                : sprintf('Bestellung %s (%s): Retoure an everysize gemeldet.', $refundOrderId, $refundEverysizeOrderId),
            $returned['skipped'] === 0
        );
        if (!$doRefund) {
          $ajax_json(['ok' => true, 'reload' => true]);
        }
      }
      try {
        $refund = PhoenixFactory::client($settings)->refund(
            $refundEverysizeOrderId,
            $refundAmount,
            $refundKey !== '' ? $refundKey : 'manual-'.bin2hex(random_bytes(8))
        );
        $refundStatus = strtoupper((string) ($refund['status'] ?? ''));
        $refundId = (string) ($refund['refundId'] ?? '');
        $refundPaidAmount = (string) ($refund['amount'] ?? $refundAmount);
        $refundCurrency = (string) ($refund['currency'] ?? '');
        $alreadyRefunded = $refundStatus === 'ALREADY_REFUNDED';
        // Idempotent nachmelden: genau ein Report je orders_id + kind + sku.
        $links->addReport(
            $refundOrderId,
            OrderLinks::KIND_REFUND,
            $refundId !== '' ? $refundId : $refundStatus,
            number_format((float) $refundAmount, 2, '.', ''),
            $alreadyRefunded ? OrderLinks::RESULT_KNOWN : OrderLinks::RESULT_SENT
        );
        if ($alreadyRefunded) {
          $protocol->add(
              'Erstattung im Bestelldetail: bereits erstattet',
              sprintf('Bestellung %s (%s): everysize meldet die Zahlung als bereits erstattet, nichts wurde erneut erstattet.', $refundOrderId, $refundEverysizeOrderId),
              true
          );
        } else {
          $links->addHistoryComment($refundOrderId, OrderLinks::refundComment($refundPaidAmount, $refundCurrency, $refundId, false));
          $protocol->add(
              'Erstattung im Bestelldetail',
              sprintf('Bestellung %s (%s): %s %s erstattet, PayPal-Erstattung %s.', $refundOrderId, $refundEverysizeOrderId, $protocolAmount($refundPaidAmount), $refundCurrency !== '' ? $refundCurrency : 'EUR', $refundId !== '' ? $refundId : $refundStatus),
              true
          );
        }
        $ajax_json(['ok' => true, 'reload' => true]);
      } catch (ApiException $e) {
        $refundMessage = ($returnMessage !== '' ? $returnMessage.' ' : '')
            .($boxTexts['refund_'.OrderBox::refundReasonKey($e->getReason())] ?? $boxTexts['refund_failed'] ?? 'Refund failed')
            .' ('.$e->getMessage().')';
        $protocol->add(
            'Erstattung im Bestelldetail fehlgeschlagen',
            sprintf('Bestellung %s (%s), %s EUR: %s', $refundOrderId, $refundEverysizeOrderId, $protocolAmount($refundAmount), $e->getMessage()),
            false
        );
        $ajax_json(['ok' => false, 'message' => $refundMessage]);
      } catch (\Throwable $e) {
        $protocol->add(
            'Erstattung im Bestelldetail fehlgeschlagen',
            sprintf('Bestellung %s (%s), %s EUR: %s', $refundOrderId, $refundEverysizeOrderId ?? '', $protocolAmount($refundAmount), $e->getMessage()),
            false
        );
        $ajax_json(['ok' => false, 'message' => ($returnMessage !== '' ? $returnMessage.' ' : '').($boxTexts['refund_failed'] ?? 'Refund failed').' ('.$e->getMessage().')']);
      }
      break;

    case 'orderTracking':
      $boxTexts = OrderBox::loadTexts(OrderBox::languageDirectory($db_connection, (int) ($_SESSION['languages_id'] ?? 0)));
      if (!$installed) {
        $ajax_json(['ok' => false, 'message' => 'not installed']);
      }
      $trackingOrderId = (string) (int) ($_POST['order_id'] ?? 0);
      $trackingCode = mb_substr(trim((string) Text::input($_POST['tracking_code'] ?? '')), 0, 255);
      $trackingCarrier = trim((string) Text::input($_POST['carrier'] ?? ''));
      if ($trackingOrderId === '0') {
        $ajax_json(['ok' => false, 'message' => 'invalid order']);
      }
      // Nur bekannte Carrier durchlassen, sonst der Modul-Default.
      if ($trackingCarrier !== '' && !\in_array($trackingCarrier, PhoenixSettings::CARRIERS, true)) {
        $trackingCarrier = '';
      }
      (new OrderLinks($db_connection, $settings))->setTrackingCode($trackingOrderId, $trackingCode, $trackingCarrier);
      $ajax_json(['ok' => true, 'message' => $boxTexts['tracking_saved'] ?? 'saved']);
      break;

    case 'save':
      $clientId = trim((string) Text::input($_POST['clientId'] ?? ''));
      $secret = (string) ($_POST['clientSecret'] ?? '');
      $secretInput = trim((string) $secret);

      // Leer = "behalten" (beide Felder): ein leeres Speichern überschreibt
      // die Zugangsdaten nie — sie werden nur über removeCredentials entfernt.
      if ($clientId !== '') {
        $settings->set('clientId', $clientId);
      }
      // Leer oder nur Maskierungszeichen (Browser-Platzhalter) = "behalten".
      if (!$settings->secretIsEmptyPlaceholder($secretInput)) {
        $settings->set('clientSecret', $secret);
      }

      $carrier = trim((string) Text::input($_POST['carrier'] ?? ''));
      $settings->set('carrier', mb_substr($carrier, 0, 31));

      $settings->set('importStatus', (string) (int) ($_POST['importStatus'] ?? 0));
      $settings->set('sendOrderConfirmation', isset($_POST['sendOrderConfirmation']) && $_POST['sendOrderConfirmation'] === '1' ? '1' : '0');
      $settings->set('syncEnabled', isset($_POST['syncEnabled']) && $_POST['syncEnabled'] === '1' ? '1' : '0');

      // Statuslisten: nur reine IDs durchlassen (StatusList::format castet).
      $settings->set('shippingStatuses', StatusList::format(array_map('intval', (array) ($_POST['shippingStatuses'] ?? []))));
      $settings->set('cancellationStatuses', StatusList::format(array_map('intval', (array) ($_POST['cancellationStatuses'] ?? []))));
      $settings->set('returnStatuses', StatusList::format(array_map('intval', (array) ($_POST['returnStatuses'] ?? []))));

      $autoRefundWasOn = $settings->isAutoRefundDecline();
      $autoRefundOn = isset($_POST['autoRefund']) && $_POST['autoRefund'] === '1';
      $settings->set('autoRefund', $autoRefundOn ? '1' : '0');
      if ($autoRefundOn && !$autoRefundWasOn) {
        // Ab jetzt stornierte Bestellungen werden automatisch erstattet.
        $settings->setAutoRefundSinceNow();
      }

      $messageStack->add_session(TEXT_SAVE_SUCCESS, 'success');
      Href::redirect($Admin->link('everysize_checkout.php'));
      break;

    case 'test':
      // Verbindungstest mit den EINGEGEBENEN Werten, sonst den gespeicherten
      // (gleiche Regel wie beim Speichern); Ergebnis bleibt im Request —
      // kein Redirect, damit die Eingaben im Formular stehen bleiben.
      $testClientId = trim((string) ($_POST['clientId'] ?? ''));
      $testSecret = (string) ($_POST['clientSecret'] ?? '');
      if ($testClientId === '') {
        $testClientId = $settings->getClientId();
      }
      if ($settings->secretIsEmptyPlaceholder(trim($testSecret))) {
        $testSecret = $settings->getClientSecret();
      }

      $page_message = '<div class="alert alert-danger">'.TEXT_TEST_MISSING.'</div>';
      if ($testClientId !== '' && $testSecret !== '') {
        try {
          $client = new Client(
            $testClientId,
            $testSecret,
            ApiUrl::resolve(),
            \class_exists('Versions') ? Versions::get('Phoenix') : '',
            ModuleInfo::VERSION
          );
          $shopId = $client->testConnection();
          $page_message = '<div class="alert alert-success">'.sprintf(TEXT_TEST_SUCCESS, (int) $shopId, htmlspecialchars(ApiUrl::resolve())).'</div>';
        } catch (ApiException $e) {
          $page_message = '<div class="alert alert-danger">'.TEXT_TEST_FAILED.': '.htmlspecialchars($e->getMessage()).'</div>';
        } catch (\Throwable $e) {
          $page_message = '<div class="alert alert-danger">'.TEXT_TEST_FAILED.': '.htmlspecialchars($e->getMessage()).'</div>';
        }
      }
      break;
  }

// --- Statusliste für die Auswahl (aktuelle Admin-Sprache) ------------------

  $status_options = [];
  if ($installed) {
    foreach ($db->query(sprintf(
      "SELECT orders_status_id, orders_status_name FROM orders_status WHERE language_id = '%s' ORDER BY orders_status_id",
      (int) ($_SESSION['languages_id'] ?? 1)
    )) as $row) {
      $status_options[] = ['id' => (string) $row['orders_status_id'], 'text' => $row['orders_status_id'].' — '.$row['orders_status_name']];
    }
  }

  $carrier_options = [];
  foreach (PhoenixSettings::CARRIERS as $carrier) {
    $carrier_options[] = ['id' => $carrier, 'text' => $carrier];
  }

  $env = ApiUrl::describe();
  $env_label = [
    'production' => TEXT_ENV_PRODUCTION,
    'test' => TEXT_ENV_STAGE,
    'invalid' => TEXT_ENV_INVALID,
  ][$env['environment']] ?? TEXT_ENV_INVALID;
  $env_badge = ['production' => 'bg-danger', 'test' => 'bg-success', 'invalid' => 'bg-secondary'][$env['environment']] ?? 'bg-secondary';

  require 'includes/template_top.php';
?>

  <div class="row">
    <div class="col">
      <h1 class="display-4 mb-2"><?= HEADING_TITLE ?></h1>
    </div>
    <div class="col-12 col-lg-6 text-start text-lg-end align-self-center pb-1">
      <?= $admin_hooks->cat('extraButtons') ?>
    </div>
  </div>

<?php if (!$installed) { ?>

  <div class="alert alert-info"><?= TEXT_NOT_INSTALLED ?></div>

  <?= (new Form('install', $Admin->link('everysize_checkout.php', ['action' => 'install']))) ?>
    <?= new Button(TEXT_INSTALL, 'fas fa-plug', 'btn-primary') ?>
  <?= (new Form('install', ''))->close() ?>

<?php } else {

    // Umgebungsanzeige (Produktion/Testumgebung/ungültig) + Hinweis bei ungültiger API-URL.
    if ($env['environment'] === 'invalid') { ?>
      <div class="alert alert-warning"><?= TEXT_ENV_INVALID_HINT ?> (<?= htmlspecialchars($env['error']) ?>)</div>
    <?php } ?>

  <div class="row mb-3">
    <div class="col">
      <div class="card">
        <div class="card-body py-2">
          <strong><?= TEXT_ENVIRONMENT ?>:</strong>
          <span class="badge <?= $env_badge ?>"><?= $env_label ?></span>
          <?php if ($env['url'] !== '') { ?> <code><?= htmlspecialchars($env['url']) ?></code><?php } ?>
          &nbsp;·&nbsp; <strong><?= TEXT_MODULE_VERSION ?>:</strong> <?= ModuleInfo::VERSION ?>
        </div>
      </div>
    </div>
  </div>

  <?= $page_message ?>

  <div class="row">
    <div class="col-12 col-xl-8">

      <?= (new Form('everysize_settings', $Admin->link('everysize_checkout.php', ['action' => 'save']))) ?>

      <h3 class="mt-2"><?= TEXT_HEADING_CREDENTIALS ?></h3>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right" for="clientId"><?= TEXT_CLIENT_ID ?></label>
        <div class="col-sm-8"><?= new Input('clientId', ['value' => htmlspecialchars($settings->getClientId()), 'autocomplete' => 'off']) ?></div>
      </div>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right" for="clientSecret"><?= TEXT_CLIENT_SECRET ?></label>
        <div class="col-sm-8">
          <?= new Input('clientSecret', ['type' => 'password', 'autocomplete' => 'new-password', 'placeholder' => $settings->isConfigured() ? TEXT_SECRET_STORED : '']) ?>
          <div class="form-text"><?= TEXT_SECRET_HINT ?></div>
        </div>
      </div>

      <h3 class="mt-2"><?= TEXT_HEADING_IMPORT ?></h3>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right" for="importStatus"><?= TEXT_IMPORT_STATUS ?></label>
        <div class="col-sm-8"><?= new Select('importStatus', $status_options, ['class' => 'form-select', 'value' => $settings->getImportStatus()]) ?></div>
      </div>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right"><?= TEXT_SEND_ORDER_CONFIRMATION ?></label>
        <div class="col-sm-8">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="sendOrderConfirmation" id="sendOrderConfirmation1" value="1"<?= $settings->sendOrderConfirmation() ? ' checked' : '' ?>>
            <label class="form-check-label" for="sendOrderConfirmation1"><?= TEXT_YES ?></label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="sendOrderConfirmation" id="sendOrderConfirmation0" value="0"<?= $settings->sendOrderConfirmation() ? '' : ' checked' ?>>
            <label class="form-check-label" for="sendOrderConfirmation0"><?= TEXT_NO ?></label>
          </div>
          <div class="form-text"><?= TEXT_SEND_ORDER_CONFIRMATION_HINT ?></div>
        </div>
      </div>

      <h3 class="mt-2"><?= TEXT_HEADING_EXPORT ?></h3>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right"><?= TEXT_SHIPPING_STATUSES ?></label>
        <div class="col-sm-8">
          <select class="form-select" name="shippingStatuses[]" multiple size="5">
            <?php foreach ($status_options as $option) {
              $selected = \in_array((int) $option['id'], $settings->getShippingStatuses(), true) ? ' selected' : ''; ?>
              <option value="<?= (int) $option['id'] ?>"<?= $selected ?>><?= htmlspecialchars($option['text']) ?></option>
            <?php } ?>
          </select>
          <div class="form-text"><?= TEXT_SHIPPING_STATUSES_HINT ?></div>
        </div>
      </div>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right"><?= TEXT_CANCELLATION_STATUSES ?></label>
        <div class="col-sm-8">
          <select class="form-select" name="cancellationStatuses[]" multiple size="5">
            <?php foreach ($status_options as $option) {
              $selected = \in_array((int) $option['id'], $settings->getCancellationStatuses(), true) ? ' selected' : ''; ?>
              <option value="<?= (int) $option['id'] ?>"<?= $selected ?>><?= htmlspecialchars($option['text']) ?></option>
            <?php } ?>
          </select>
        </div>
      </div>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right"><?= TEXT_RETURN_STATUSES ?></label>
        <div class="col-sm-8">
          <select class="form-select" name="returnStatuses[]" multiple size="5">
            <?php foreach ($status_options as $option) {
              $selected = \in_array((int) $option['id'], $settings->getReturnStatuses(), true) ? ' selected' : ''; ?>
              <option value="<?= (int) $option['id'] ?>"<?= $selected ?>><?= htmlspecialchars($option['text']) ?></option>
            <?php } ?>
          </select>
          <div class="form-text"><?= TEXT_RETURN_STATUSES_HINT ?></div>
        </div>
      </div>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right" for="carrier"><?= TEXT_CARRIER ?></label>
        <div class="col-sm-8">
          <?= new Select('carrier', $carrier_options, ['class' => 'form-select', 'value' => htmlspecialchars($settings->getCarrier())]) ?>
          <div class="form-text"><?= TEXT_CARRIER_HINT ?></div>
        </div>
      </div>

      <h3 class="mt-2"><?= TEXT_HEADING_REFUND ?></h3>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right"><?= TEXT_AUTO_REFUND ?></label>
        <div class="col-sm-8">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="autoRefund" id="autoRefund1" value="1"<?= $settings->isAutoRefundDecline() ? ' checked' : '' ?>>
            <label class="form-check-label" for="autoRefund1"><?= TEXT_YES ?></label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="autoRefund" id="autoRefund0" value="0"<?= $settings->isAutoRefundDecline() ? '' : ' checked' ?>>
            <label class="form-check-label" for="autoRefund0"><?= TEXT_NO ?></label>
          </div>
          <div class="form-text"><?= TEXT_AUTO_REFUND_HINT ?>
          <?php $since = $settings->getAutoRefundSince(); if ($since !== '') { ?>
            (<?= sprintf(TEXT_AUTO_REFUND_SINCE, htmlspecialchars(
                (static function (string $v): string {
                    try { return (new DateTimeImmutable($v))->format('d.m.Y H:i'); } catch (Exception $e) { return $v; }
                })($since)
            )) ?>)
          <?php } ?>
          </div>
        </div>
      </div>

      <h3 class="mt-2"><?= TEXT_HEADING_SYNC ?></h3>
      <div class="mb-3 row">
        <label class="col-form-label col-sm-4 text-start text-sm-right"><?= TEXT_SYNC_ENABLED ?></label>
        <div class="col-sm-8">
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="syncEnabled" id="syncEnabled1" value="1"<?= $settings->isSyncEnabled() ? ' checked' : '' ?>>
            <label class="form-check-label" for="syncEnabled1"><?= TEXT_YES ?></label>
          </div>
          <div class="form-check form-check-inline">
            <input class="form-check-input" type="radio" name="syncEnabled" id="syncEnabled0" value="0"<?= $settings->isSyncEnabled() ? '' : ' checked' ?>>
            <label class="form-check-label" for="syncEnabled0"><?= TEXT_NO ?></label>
          </div>
          <div class="form-text"><?= TEXT_SYNC_ENABLED_HINT ?></div>
          <?php if ($settings->isSyncEnabled()) { ?>
            <div class="form-text"><?= TEXT_SYNC_TRIGGER_HINT ?></div>
          <?php } ?>
        </div>
      </div>
      <?php if ($settings->isSyncEnabled()) { ?>
        <div class="mb-3 row">
          <div class="col-sm-8 offset-sm-4">
            <?php /* Eigenes Formular (unterhalb), der Button ist per form-Attribut verknüpft —
                     kein Formular im Formular (das </form> des inneren würde das äußere schließen). */ ?>
            <button type="submit" class="btn btn-outline-primary" form="everysize_sync"><i class="fas fa-rotate me-2"></i><?= TEXT_SYNC_NOW ?></button>
          </div>
        </div>
      <?php } ?>

      <div class="mt-4">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?= TEXT_SAVE ?></button>
        <button type="submit" class="btn btn-secondary" formaction="<?= htmlspecialchars((string) $Admin->link('everysize_checkout.php', ['action' => 'test'])) ?>"><i class="fas fa-plug-circle-check me-2"></i><?= TEXT_TEST_CONNECTION ?></button>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars((string) $Admin->link('everysize_checkout_protocol.php')) ?>"><i class="fas fa-clipboard-list me-2"></i><?= TEXT_PROTOCOL_LINK ?></a>
      </div>
      <?= (new Form('everysize_settings', ''))->close() ?>

      <?php if ($settings->isSyncEnabled()) { ?>
        <?= (new Form('everysize_sync', $Admin->link('everysize_checkout.php', ['action' => 'sync']), 'post', ['id' => 'everysize_sync'])) ?>
        <?= (new Form('everysize_sync', ''))->close() ?>
      <?php } ?>

      <div class="mt-3 d-flex gap-2 flex-wrap">
        <?= (new Form('everysize_remove_credentials', $Admin->link('everysize_checkout.php', ['action' => 'removeCredentials']), 'post', ['onsubmit' => 'return confirm(\''.TEXT_REMOVE_CREDENTIALS_CONFIRM.'\');'])) ?>
          <button type="submit" class="btn btn-outline-danger"><i class="fas fa-key me-2"></i><?= TEXT_REMOVE_CREDENTIALS ?></button>
        <?= (new Form('everysize_remove_credentials', ''))->close() ?>
      </div>

      <h3 class="mt-4 text-danger"><?= TEXT_HEADING_DANGER ?></h3>
      <?= (new Form('everysize_remove', $Admin->link('everysize_checkout.php', ['action' => 'remove']), 'post', ['onsubmit' => 'return confirm(\''.TEXT_REMOVE_CONFIRM.'\');'])) ?>
        <button type="submit" class="btn btn-outline-danger"><i class="fas fa-trash me-2"></i><?= TEXT_REMOVE ?></button>
      <?= (new Form('everysize_remove', ''))->close() ?>

    </div>
  </div>

<?php } ?>

<?php
  require 'includes/template_bottom.php';
  require 'includes/application_bottom.php';
