<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

use Everysize\Checkout\Db\DbInterface;

/**
 * Install/uninstall of the module tables and flags. Idempotent — the
 * tables are CREATE TABLE IF NOT EXISTS, the schema version only moves
 * forward. **Removing keeps all data** (tables, credentials, order
 * links, protocol): it only clears the installed flag, so install()
 * later simply continues where it left off (like the other everysize add-ons).
 *
 * Module tables (per task; Phoenix-specific: tracking_code/carrier in
 * everysize_checkout_order, id in everysize_checkout_order_item):
 * - everysize_checkout_settings (name/value)
 * - everysize_checkout_order (orders_id PK, everysize_order_id UNIQUE)
 * - everysize_checkout_order_item (orders_id, orders_products_id, sku)
 * - everysize_checkout_report (UNIQUE orders_id+kind+sku → exactly once)
 * - everysize_checkout_protocol (title, message, state, created_at)
 * No foreign keys (the shop might purge order rows independently).
 */
class Installer
{
    public const SCHEMA_VERSION = 2;

    private const SETTINGS_TABLE = 'everysize_checkout_settings';

    /** @var list<string> */
    private array $tables = [
        'CREATE TABLE IF NOT EXISTS `'.self::SETTINGS_TABLE.'` (
            `name` VARCHAR(64) NOT NULL,
            `value` TEXT NOT NULL,
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS `everysize_checkout_order` (
            `orders_id` INT(11) NOT NULL,
            `everysize_order_id` VARCHAR(255) NOT NULL,
            `paypal_capture_id` VARCHAR(255) NOT NULL DEFAULT \'\',
            `original_email` VARCHAR(255) NOT NULL DEFAULT \'\',
            `number_reported` DATETIME NULL DEFAULT NULL,
            `tracking_code` VARCHAR(255) NOT NULL DEFAULT \'\',
            `carrier` VARCHAR(31) NOT NULL DEFAULT \'\',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`orders_id`),
            UNIQUE KEY `everysize_order_id` (`everysize_order_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS `everysize_checkout_order_item` (
            `everysize_checkout_order_item_id` INT(11) NOT NULL AUTO_INCREMENT,
            `orders_id` INT(11) NOT NULL,
            `orders_products_id` INT(11) NOT NULL,
            `sku` VARCHAR(255) NOT NULL,
            PRIMARY KEY (`everysize_checkout_order_item_id`),
            KEY `orders_id` (`orders_id`),
            KEY `sku` (`sku`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS `everysize_checkout_report` (
            `orders_id` INT(11) NOT NULL,
            `kind` VARCHAR(32) NOT NULL,
            `sku` VARCHAR(255) NOT NULL DEFAULT \'\',
            `reason` VARCHAR(255) NOT NULL DEFAULT \'\',
            `result` VARCHAR(32) NOT NULL,
            `created_at` DATETIME NOT NULL,
            UNIQUE KEY `report_once` (`orders_id`, `kind`, `sku`),
            KEY `orders_id` (`orders_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        'CREATE TABLE IF NOT EXISTS `everysize_checkout_protocol` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `title` VARCHAR(255) NOT NULL DEFAULT \'\',
            `message` TEXT NOT NULL,
            `state` VARCHAR(16) NOT NULL DEFAULT \'\',
            `created_at` DATETIME NOT NULL,
            `title_key` VARCHAR(63) NULL DEFAULT NULL,
            `title_params` TEXT NULL,
            PRIMARY KEY (`id`),
            KEY `created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
    ];

    private DbInterface $db;

    public function __construct(DbInterface $db)
    {
        $this->db = $db;
    }

    public function isInstalled(): bool
    {
        try {
            return (string) $this->settingsValue('installed') === '1';
        } catch (\Throwable $e) {
            // Table missing (fresh shop or files copied without install).
            return false;
        }
    }

    /**
     * Creates the module tables (if missing), marks the schema version and
     * the installed flag. Existing data is never touched.
     */
    public function install(): void
    {
        foreach ($this->tables as $sql) {
            $this->db->execute($sql);
        }

        $stored = (int) ($this->settingsValue('schemaVersion') ?? '0');
        if ($stored < self::SCHEMA_VERSION) {
            // Schema 1 → 2: translation keys for the protocol titles (fresh
            // shops get the columns with the CREATE above).
            if ($stored < 2 && !$this->columnExists('everysize_checkout_protocol', 'title_key')) {
                $this->db->execute('ALTER TABLE `everysize_checkout_protocol`
                    ADD COLUMN `title_key` VARCHAR(63) NULL DEFAULT NULL,
                    ADD COLUMN `title_params` TEXT NULL');
            }
            $this->writeSetting('schemaVersion', (string) self::SCHEMA_VERSION);
        }

        $firstInstall = $this->settingsValue('installed') === null;
        $this->writeSetting('installed', '1');
        if ($firstInstall && $this->settingsValue('autoRefundSince') === null) {
            // Cancellations from now on are refunded automatically (never
            // older ones) — the merchant can change it by toggling again.
            $this->writeSetting('autoRefundSince', date('Y-m-d H:i:s'));
        }
    }

    /**
     * Keeps every table and every row — credentials, order links, reports,
     * protocol. Only the installed flag goes, so the sync trigger does
     * nothing until the module is installed again.
     */
    public function remove(): void
    {
        $this->writeSetting('installed', '0');
    }

    /** Whether a column exists (MariaDB/MySQL have no portable ADD COLUMN IF NOT EXISTS). */
    private function columnExists(string $table, string $column): bool
    {
        // SHOW statements take no bound parameters; both names are code
        // constants, never user input.
        return $this->db->fetchRows(
            "SHOW COLUMNS FROM `".str_replace('`', '', $table)."` LIKE '".str_replace("'", '', $column)."'"
        ) !== [];
    }

    /** Value of one settings row; null when unset, throws when the table is missing. */
    private function settingsValue(string $name): ?string
    {
        return $this->db->fetchValue(
            'SELECT value FROM '.self::SETTINGS_TABLE.' WHERE name = :name LIMIT 1',
            [':name' => $name]
        );
    }

    private function writeSetting(string $name, string $value): void
    {
        $this->db->execute(
            'INSERT INTO '.self::SETTINGS_TABLE.' (name, value) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [':name' => $name, ':value' => $value]
        );
    }
}
