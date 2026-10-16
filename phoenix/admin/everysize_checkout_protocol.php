<?php
/*
  $Id$

  everysize Checkout — Modul-Protokoll (Admin): die Einträge der
  everysize_checkout_protocol-Tabelle (Abgleich, Erstattung aus der Box),
  neueste zuerst, gefiltert nach Status (alle/ok/Fehler), 50 pro Seite.
  created_at schreibt das Add-on in PHP-Zeit (Shop-Zeit) und wird als
  d.m.Y H:i angezeigt. Titel und Meldungszeilen des Abgleichs werden über
  die Admin-Sprachdatei übersetzt (title_key/title_params aus Schema 2);
  unbekannte Zeilen und die technischen Details der everysize-API stehen
  roh — sie sind für die Fehlersuche gedacht.

  Die Seite ist eine eigene Admin-Seite nach dem Muster der
  everysize_checkout.php (Menü über admin/includes/boxes/).
*/

  require 'includes/application_top.php';

  require DIR_FS_CATALOG.'ext/everysize_checkout/autoload.php';

  use Everysize\Checkout\Phoenix\Installer;
  use Everysize\Checkout\Phoenix\PhoenixDb;
  use Everysize\Checkout\Phoenix\PhoenixProtocol;

  $eycDb = PhoenixDb::fromShopDefines();
  $installer = new Installer($eycDb);
  $installed = $installer->isInstalled();

  /** A stored text through the language file (title_key) or as it is. */
  $translate = static function (string $raw, ?string $key, ?string $paramsJson): string {
    if ($key === null || $key === '') {
      return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    }
    $constant = 'TEXT_PROTOCOL_'.strtoupper(str_replace('.', '_', $key));
    if (!\defined($constant)) {
      return htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    }
    $params = $paramsJson !== null && $paramsJson !== '' ? (array) json_decode($paramsJson, true) : [];
    if ($params === []) {
      return (string) \constant($constant);
    }
    $params = array_map(static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'), $params);

    return vsprintf((string) \constant($constant), $params);
  };
  $renderTitle = static function (array $entry) use ($translate): string {
    return $translate(
      (string) $entry['title'],
      $entry['title_key'] ?? null,
      $entry['title_params'] ?? null
    );
  };
  /** Report lines one by one (the message keeps the raw details otherwise). */
  $renderMessage = static function (string $raw): string {
    $out = [];
    foreach (explode("\n", $raw) as $line) {
      if (trim($line) === '') {
        continue;
      }
      [$key, $params] = PhoenixProtocol::classifyMessage($line);
      $constant = $key !== null ? 'TEXT_PROTOCOL_'.strtoupper(str_replace('.', '_', $key)) : '';
      if ($constant !== '' && \defined($constant)) {
        $params = array_map(static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'), $params);
        $out[] = vsprintf((string) \constant($constant), $params);
        continue;
      }
      $out[] = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');
    }

    return implode('<br>', $out);
  };
  /** Shop time (the module writes PHP time) as d.m.Y H:i. */
  $shopTime = static function (string $dbValue): string {
    try {
      return (new DateTimeImmutable($dbValue))->format('d.m.Y H:i');
    } catch (Exception $e) {
      return $dbValue;
    }
  };


  $states = ['all' => TEXT_PROTOCOL_STATE_ALL, 'ok' => TEXT_PROTOCOL_STATE_OK, 'error' => TEXT_PROTOCOL_STATE_ERROR];
  $state = $_GET['state'] ?? 'all';
  if (!isset($states[$state])) {
    $state = 'all';
  }
  $page = max(1, (int) ($_GET['page'] ?? 1));
  $perPage = 50;

  $entries = [];
  $total = 0;
  if ($installed) {
    $where = $state === 'all' ? '' : " WHERE state = '".($state === 'ok' ? 'ok' : 'error')."'";
    $total = (int) $eycDb->fetchValue('SELECT COUNT(*) FROM everysize_checkout_protocol'.$where);
    if ($total > 0) {
      $offset = ($page - 1) * $perPage;
      if ($offset >= $total) {
        $page = (int) ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;
      }
      $entries = $eycDb->fetchRows(
        'SELECT id, title, message, state, created_at, title_key, title_params FROM everysize_checkout_protocol'.$where
        .' ORDER BY id DESC LIMIT '.(int) $perPage.' OFFSET '.(int) $offset
      );
    }
  }

  $pageCount = max(1, (int) ceil($total / $perPage));
  $stateLink = static function (string $stateKey) use ($Admin) {
    return (string) $Admin->link('everysize_checkout_protocol.php', $stateKey === 'all' ? [] : ['state' => $stateKey]);
  };
  $pageLink = static function (int $pageNumber) use ($Admin, $state) {
    return (string) $Admin->link('everysize_checkout_protocol.php', $state === 'all' ? ['page' => $pageNumber] : ['state' => $state, 'page' => $pageNumber]);
  };

  require 'includes/template_top.php';
?>

  <div class="row">
    <div class="col">
      <h1 class="display-4 mb-2"><?= HEADING_TITLE ?></h1>
    </div>
  </div>

<?php if (!$installed) { ?>

  <div class="alert alert-info"><?= TEXT_PROTOCOL_NOT_INSTALLED ?></div>

<?php } else { ?>

  <div class="row mb-3">
    <div class="col">
      <div class="btn-group" role="group">
<?php foreach ($states as $stateKey => $stateLabel) { ?>
        <a class="btn btn-outline-secondary<?= $stateKey === $state ? ' active' : '' ?>" href="<?= $stateLink($stateKey) ?>"><?= $stateLabel ?></a>
<?php } ?>
      </div>
    </div>
    <div class="col text-end text-muted align-self-center">
      <?= sprintf(TEXT_PROTOCOL_COUNT, $total) ?>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table table-striped table-hover">
      <thead class="table-dark">
        <tr>
          <th style="width:150px"><?= TEXT_PROTOCOL_TIME ?></th>
          <th style="width:110px"><?= TEXT_PROTOCOL_STATE ?></th>
          <th style="width:38%"><?= TEXT_PROTOCOL_TITLE ?></th>
          <th><?= TEXT_PROTOCOL_MESSAGE ?></th>
        </tr>
      </thead>
      <tbody>
<?php if ($entries === []) { ?>
        <tr><td colspan="4" class="text-muted"><?= TEXT_PROTOCOL_EMPTY ?></td></tr>
<?php } foreach ($entries as $entry) { ?>
        <tr>
          <td><?= $shopTime($entry['created_at']) ?></td>
          <td><span class="badge bg-<?= $entry['state'] === 'ok' ? 'success' : 'danger' ?>"><?= $entry['state'] === 'ok' ? TEXT_PROTOCOL_STATE_OK : TEXT_PROTOCOL_STATE_ERROR ?></span></td>
          <td style="word-break:break-word"><?= $renderTitle($entry) ?></td>
          <td style="word-break:break-word"><?= $renderMessage((string) $entry['message']) ?></td>
        </tr>
<?php } ?>
      </tbody>
    </table>
  </div>

<?php if ($pageCount > 1) { ?>
  <nav>
    <ul class="pagination">
      <li class="page-item<?= $page <= 1 ? ' disabled' : '' ?>"><a class="page-link" href="<?= $pageLink(max(1, $page - 1)) ?>">&laquo;</a></li>
<?php for ($p = 1; $p <= $pageCount; ++$p) { ?>
      <li class="page-item<?= $p === $page ? ' active' : '' ?>"><a class="page-link" href="<?= $pageLink($p) ?>"><?= $p ?></a></li>
<?php } ?>
      <li class="page-item<?= $page >= $pageCount ? ' disabled' : '' ?>"><a class="page-link" href="<?= $pageLink(min($pageCount, $page + 1)) ?>">&raquo;</a></li>
    </ul>
  </nav>
<?php } ?>

<?php } ?>

<?php require 'includes/template_bottom.php';
