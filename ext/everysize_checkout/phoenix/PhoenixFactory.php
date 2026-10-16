<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

use Everysize\Checkout\Api\ApiUrl;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Export\StatusExporter;
use Everysize\Checkout\ModuleInfo;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Sync;

/**
 * Builds the module core's services for the Phoenix layer (admin page and
 * CLI): one mysqli-backed Db, the module-table settings, the API client
 * (URL from the environment via ApiUrl::resolve, never a silent fallback to
 * production) and the sync with the Phoenix order importer. Credentials
 * missing → Sync itself does nothing and exits 0.
 */
class PhoenixFactory
{
    public static function db(): PhoenixDb
    {
        return PhoenixDb::fromShopDefines();
    }

    /**
     * Phoenix's version for the API ("1.1.0.8"): the Versions class of the
     * running request (admin pages) or the PROJECT_VERSION define (CLI boot).
     */
    public static function shopSystemVersion(): string
    {
        if (\class_exists('\Versions')) {
            return (string) \Versions::get('Phoenix');
        }
        if (\defined('PROJECT_VERSION')) {
            return (string) PROJECT_VERSION;
        }

        return 'unknown';
    }

    public static function client(PhoenixSettings $settings): Client
    {
        return new Client(
            (string) $settings->getClientId(),
            (string) $settings->getClientSecret(),
            ApiUrl::resolve(),
            self::shopSystemVersion(),
            ModuleInfo::VERSION
        );
    }

    /** All services wired; $db shared so importer, sync and factory use one connection. */
    public static function sync(?PhoenixDb $db = null): Sync
    {
        $db = $db ?? self::db();
        $settings = new PhoenixSettings($db);
        $client = self::client($settings);
        $links = new OrderLinks($db, $settings);
        $importer = new PhoenixOrderImporter($db, $settings, $links, new PhoenixOrderMailer($db));
        $exporter = new StatusExporter($client, $settings, $links);
        $protocol = new PhoenixProtocol($db);

        return new Sync($settings, $client, $db, $importer, $exporter, $links, $protocol);
    }
}
