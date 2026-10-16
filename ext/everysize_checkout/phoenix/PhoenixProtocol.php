<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

use Everysize\Checkout\Protocol;

/**
 * Module protocol in everysize_checkout_protocol: one row per sync event
 * (state ok/error), cleanup after 31 days keeping at most the newest 1000
 * rows. Same behaviour as the Gambio module.
 *
 * The core writes English titles; this layer stores a translation key and
 * its parameters next to the raw text (schema v2, title_key/title_params).
 * The protocol page renders the key through the admin language file and
 * falls back to the raw title. Timestamps are written in PHP time (shop
 * time) — NOW() would be the MySQL session zone (UTC on most stacks).
 */
class PhoenixProtocol implements Protocol
{
    /** Rows kept after a cleanup run, newest first. */
    public const MAX_ROWS = 1000;

    /**
     * Known sync titles of the core, as translation key + captured numbers.
     * Everything unknown is stored without a key (shown raw) — the core
     * might add texts, the page falls back gracefully.
     */
    private const TITLE_PATTERNS = [
        '/^Imported (\d+) of (\d+) orders from everysize$/'
            => ['sync.import.summary', ['imported', 'total']],
        '/^Reported to everysize: (\d+) shipped, (\d+) tracking codes added later, (\d+) cancelled, (\d+) returned, (\d+) refunded automatically, (\d+) skipped \(revoked at everysize\)$/'
            => ['sync.export.summary', ['shipped', 'tracking', 'cancelled', 'returned', 'autoRefunds', 'skipped']],
        '/^Import from everysize failed$/'
            => ['sync.import.failed', []],
        '/^Report to everysize failed$/'
            => ['sync.export.failed', []],
    ];

    private PhoenixDb $db;

    public function __construct(PhoenixDb $db)
    {
        $this->db = $db;
    }

    public function add(string $title, string $message, bool $success): void
    {
        [$key, $params] = self::classifyTitle($title);
        $this->db->execute(
            'INSERT INTO everysize_checkout_protocol (title, message, state, created_at, title_key, title_params)
             VALUES (:title, :message, :state, :created, :key, :params)',
            [
                ':title'    => mb_substr($title, 0, 255),
                ':message'  => $message,
                ':state'    => $success ? 'ok' : 'error',
                ':created'  => date('Y-m-d H:i:s'),
                ':key'      => $key,
                ':params'   => $params !== [] ? json_encode(array_map('strval', $params), JSON_THROW_ON_ERROR) : null,
            ]
        );
    }

    /**
     * Matches a raw sync title against the known patterns.
     *
     * @return array{0: string|null, 1: list<string>} translation key and parameters, both empty when unknown
     */
    public static function classifyTitle(string $title): array
    {
        foreach (self::TITLE_PATTERNS as $pattern => [$key, $names]) {
            if (preg_match($pattern, $title, $m) === 1) {
                array_shift($m);

                return [$key, array_values($m)];
            }
        }

        return [null, []];
    }

    /**
     * Matches one report line (the protocol message is a line list) against
     * the known error/warning patterns of the import and the exporter. Used
     * by the protocol page; unknown lines are shown as they are — they carry
     * the API's own details, which stay untranslated.
     *
     * @return array{0: string|null, 1: list<string>}
     */
    public static function classifyMessage(string $line): array
    {
        $patterns = [
            '/^Order (\S+) \((\S+)\): automatic refund after cancellation failed, please refund in the order box "everysize": (.*)$/s'
                => ['export.autoRefundManual', ['order', 'esOrder', 'detail']],
            '/^Order (\S+) \((\S+)\): automatic refund after cancellation failed \(retried next run\): (.*)$/s'
                => ['export.autoRefundRetry', ['order', 'esOrder', 'detail']],
            '/^Order (\S+) \((\S+)\) is revoked at everysize, shipping not reported$/'
                => ['export.revokedSkipped', ['order', 'esOrder']],
            '/^Order (\S+) \((\S+)\) is (\S+) at everysize, tracking code (\S+) not reported$/'
                => ['export.trackingSkipped', ['order', 'esOrder', 'status', 'tracking']],
            '/^Order (\S+) \((\S+)\), tracking code (\S+): (.*)$/s'
                => ['export.trackingFailed', ['order', 'esOrder', 'tracking', 'detail']],
            '/^Order (\S+) \((\S+)\), (\S+): (.*)$/s'
                => ['export.revocationFailed', ['order', 'esOrder', 'sku', 'detail']],
            '/^Order (\S+) \((\S+)\): (.*)$/s'
                => ['export.orderFailed', ['order', 'esOrder', 'detail']],
            '/^(\S+): (\S+): (.*)$/s'
                => ['import.orderFailed', ['esOrder', 'class', 'detail']],
            '/^(\S+): (.*)$/s'
                => ['import.orderFailed', ['esOrder', 'detail']],
            '/^everysize order without everysizeOrderId skipped$/'
                => ['import.noOrderId', []],
        ];
        foreach ($patterns as $pattern => [$key, $names]) {
            if (preg_match($pattern, $line, $m) === 1) {
                array_shift($m);

                return [$key, array_values($m)];
            }
        }

        return [null, []];
    }

    public function cleanup(int $days = Protocol::RETENTION_DAYS): void
    {
        // Shop time (PHP), like every write of this module — the interval
        // argument cannot be a placeholder in MySQL, hence the computed cut.
        $cut = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
        $this->db->execute(
            'DELETE FROM everysize_checkout_protocol WHERE created_at < :cut',
            [':cut' => $cut]
        );
        $this->db->execute(
            sprintf(
                'DELETE FROM everysize_checkout_protocol WHERE id NOT IN (
                    SELECT id FROM (SELECT id FROM everysize_checkout_protocol ORDER BY id DESC LIMIT %d) keep
                )',
                self::MAX_ROWS
            )
        );
    }
}
