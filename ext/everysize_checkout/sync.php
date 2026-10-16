<?php

declare(strict_types=1);

/**
 * everysize-Abgleich: CLI-Einstieg (System-Cron und der Cron-Container des Demo-Shops).
 *
 *   php ext/everysize_checkout/sync.php
 *
 * Bootet den Shop nur über die Defines (includes/configure.php), lädt die
 * configuration-Tabelle als Konstanten (wie Phoenix' read_configuration) und
 * registriert Phoenix' Autoloader, damit die optionale Bestellbestätigung
 * über den eigenen Mailweg (Notifications) gehen kann. Exit 0 ohne
 * Zugangsdaten („nicht konfiguriert“), Exit 1 bei Fehlern, Ausgabe der
 * Zählung auf STDOUT/STDERR.
 */

use Everysize\Checkout\Phoenix\PhoenixDb;
use Everysize\Checkout\Phoenix\PhoenixFactory;

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// --- Shop booten: nur Defines + Konfiguration, kein Session/Mail-Umfeld -----

$shopRoot = dirname(__DIR__, 2); // ext/everysize_checkout/ → Shop-Root

$configure = $shopRoot.'/includes/configure.php';
if (!\is_file($configure)) {
    \fwrite(STDERR, "everysize checkout: shop configure.php not found ({$configure})\n");
    exit(1);
}
require $configure;
if (!\defined('DIR_FS_CATALOG')) {
    \fwrite(STDERR, "everysize checkout: DIR_FS_CATALOG is missing in configure.php\n");
    exit(1);
}

require __DIR__.'/autoload.php';

// Phoenix-Autoloader (für Notifications::mail der Bestellbestätigung).
$phoenixAutoloader = DIR_FS_CATALOG.'includes/system/autoloader.php';
if (\is_file($phoenixAutoloader)) {
    require $phoenixAutoloader;
    catalog_autoloader::register();
}

$db = PhoenixFactory::db();

// configuration als Konstanten — wie Phoenix es für jede Anfrage tut
// (STOCK_LIMITED, DEFAULT_LANGUAGE, STORE_NAME … lesen die Shop-Schicht
// als Defines oder über die Tabelle, beides geht hier).
foreach ($db->fetchRows('SELECT configuration_key, configuration_value FROM configuration') as $row) {
    if (!\defined($row['configuration_key'])) {
        \define($row['configuration_key'], $row['configuration_value']);
    }
}

// CHARSET kommt aus den Sprachdateien (kein configuration-Eintrag) und wird
// von der Bestellbestätigung (email-Klasse) gebraucht — Phoenix fährt
// durchgängig UTF-8.
if (!\defined('CHARSET')) {
    \define('CHARSET', 'utf-8');
}

// --- Abgleich ----------------------------------------------------------------

$result = PhoenixFactory::sync($db)->run();

foreach ($result['messages'] as $message) {
    echo $message, "\n";
}
foreach ($result['errors'] as $error) {
    \fwrite(STDERR, $error."\n");
}

exit($result['errors'] === [] ? 0 : 1);
