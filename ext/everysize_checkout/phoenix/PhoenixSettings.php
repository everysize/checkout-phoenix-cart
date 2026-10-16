<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

use Everysize\Checkout\Credentials;
use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Settings;
use Everysize\Checkout\StatusList;

/**
 * Module settings stored in everysize_checkout_settings (name/value) and
 * read through the core's Settings interface, so Sync/StatusExporter/
 * OrderImporter never touch the shop database directly.
 *
 * Stored values: clientId, clientSecret, carrier, autoRefund,
 * autoRefundSince, shippingStatuses, cancellationStatuses,
 * returnStatuses, importStatus, sendOrderConfirmation, syncEnabled —
 * plus the internal flags schemaVersion and installed.
 *
 * Secrets NEVER go into the `configuration` table: Phoenix boots every
 * configuration row as a define() (includes/configure.php boot), which
 * would put the client secret into every page and CLI process.
 *
 * Defaults differ from Gambio where the Phoenix status set differs:
 * Phoenix 1.1.0.8 seeds only 1 Pending/2 Processing/3 Delivered — the
 * demo shop's setup adds 4 Storniert and 5 Retoure, and
 * the defaults below match those. A merchant can change everything in
 * the settings page.
 */
class PhoenixSettings implements Settings
{
    public const TABLE = 'everysize_checkout_settings';

    public const DEFAULT_CARRIER         = 'DHL';
    public const DEFAULT_IMPORT_STATUS   = '2';          // Phoenix "In Bearbeitung" (paid)
    public const DEFAULT_SHIPPING_STATUS = '3';          // Phoenix "Versendet"
    public const DEFAULT_CANCEL_STATUS   = '4';          // Phoenix "Storniert" (demo shop default; Gambio used 99)
    public const DEFAULT_RETURN_STATUS   = '5';          // Phoenix "Retoure" (demo shop default; Gambio default: none)

    /** Carriers everysize knows (list shared by all everysize add-ons; max 31 chars). */
    public const CARRIERS = [
        'Cargo', 'Der Courier', 'Deutsche Post', 'DHL', 'DPD', 'eparcel', 'FedEx', 'GdSK', 'GLS', 'GO!',
        'Hermes', 'iloxx', 'Midway', 'Noxxs Logistic', 'paket.ag', 'primeMail', 'TOMBA', 'UPS',
    ];

    private DbInterface $db;

    /** Per-request cache of the settings rows (one query instead of ten). */
    private ?array $cache = null;

    public function __construct(DbInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Raw read for the admin UI; null when the key is unset.
     */
    public function get(string $name): ?string
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->fetchRows('SELECT name, value FROM '.self::TABLE) as $row) {
                $this->cache[$row['name']] = $row['value'];
            }
        }

        return $this->cache[$name] ?? null;
    }

    public function set(string $name, string $value): void
    {
        $this->db->execute(
            'INSERT INTO '.self::TABLE.' (name, value) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [':name' => $name, ':value' => $value]
        );
        $this->cache = null;
    }

    public function delete(string $name): void
    {
        $this->db->execute('DELETE FROM '.self::TABLE.' WHERE name = :name', [':name' => $name]);
        $this->cache = null;
    }

    // --- Everysize\Checkout\Settings -------------------------------------

    public function getClientId(): string
    {
        return (string) $this->get('clientId');
    }

    public function getClientSecret(): string
    {
        return (string) $this->get('clientSecret');
    }

    public function isConfigured(): bool
    {
        return $this->getClientId() !== '' && $this->getClientSecret() !== '';
    }

    public function getCarrier(): string
    {
        $carrier = trim((string) $this->get('carrier'));

        return $carrier !== '' ? $carrier : self::DEFAULT_CARRIER;
    }

    public function isAutoRefundDecline(): bool
    {
        return (string) $this->get('autoRefund') !== '0';
    }

    public function getAutoRefundSince(): string
    {
        return (string) ($this->get('autoRefundSince') ?? '');
    }

    public function getShippingStatuses(): array
    {
        return StatusList::parse($this->get('shippingStatuses')) ?: [self::DEFAULT_SHIPPING_STATUS];
    }

    public function getCancellationStatuses(): array
    {
        return StatusList::parse($this->get('cancellationStatuses')) ?: [self::DEFAULT_CANCEL_STATUS];
    }

    public function getReturnStatuses(): array
    {
        return StatusList::parse($this->get('returnStatuses'));
    }

    // --- Values the admin UI and triggers need ---------------------------

    public function getImportStatus(): string
    {
        return (string) ($this->get('importStatus') ?? self::DEFAULT_IMPORT_STATUS);
    }

    public function sendOrderConfirmation(): bool
    {
        return (string) $this->get('sendOrderConfirmation') === '1';
    }

    public function isSyncEnabled(): bool
    {
        return (string) $this->get('syncEnabled') !== '0';
    }

    /** Marks the point in time auto refunds start from (admin toggled it on). */
    public function setAutoRefundSinceNow(): void
    {
        $this->set('autoRefundSince', date('Y-m-d H:i:s'));
    }

    /**
     * A secret typed into the form that is only masking characters (or
     * empty) is a browser placeholder — "empty, keep the stored one" (B4).
     */
    public function secretIsEmptyPlaceholder(string $value): bool
    {
        return Credentials::isMaskedPlaceholder($value);
    }
}
