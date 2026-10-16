<?php

declare(strict_types=1);

namespace Everysize\Checkout;

use Everysize\Checkout\Api\Client;
use Everysize\Checkout\Db\DbInterface;
use Everysize\Checkout\Settings;

/**
 * Links Phoenix orders to everysize orders and records what was reported back.
 *
 * Every report is stored with a unique key (order, kind, SKU), so fulfillment
 * and revocation go out exactly once per order item — a later status change
 * does not touch these rows. A tracking code is stored as kind "tracking" with
 * the code in the SKU column: each code goes out once, so a code entered or
 * corrected after shipping is reported once more.
 *
 * The SQL talks to the module tables and the Phoenix order tables (orders,
 * orders_status_history) through the DbInterface, so the class is
 * unit-testable with a fake DB. All column names are taken from the Phoenix
 * 1.1.0.8 installer SQL (install/phoenix.sql) — no column is used that is not
 * in there.
 *
 * Phoenix notes (verified in the 1.1.0.8 source):
 * - There is no per-position cancel flag and no order editor: a cancellation
 *   is an order status (see admin/includes/actions/orders/update_order.php).
 * - Phoenix has no tracking-code feature (no orders_parcel_tracking_codes);
 *   the code lives in everysize_checkout_order (tracking_code, carrier).
 * - orders_status_history rows carry (orders_id, orders_status_id, date_added,
 *   customer_notified, comments) — no updated_by column.
 */
class OrderLinks
{
    public const KIND_FULFILLMENT = 'fulfillment';
    public const KIND_REVOCATION = 'revocation';
    public const KIND_TRACKING = 'tracking';
    /** A refund through everysize from the admin order box; SKU = PayPal refund ID, REASON = amount. */
    public const KIND_REFUND = 'refund';
    /** SKU of the automatic full refund after a cancellation before shipping (REASON = PayPal refund ID or why not). */
    public const AUTO_REFUND = 'auto-decline';

    /** Sent to everysize. */
    public const RESULT_SENT = 'sent';
    /** Not sent: the order was already revoked at everysize. */
    public const RESULT_SKIPPED = 'skipped';
    /** Not sent: everysize already had it. */
    public const RESULT_KNOWN = 'known';

    private DbInterface $db;
    private Settings $settings;

    public function __construct(DbInterface $db, Settings $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function findOrderId(string $everysizeOrderId): ?string
    {
        return $this->db->fetchValue(
            'SELECT orders_id FROM everysize_checkout_order WHERE everysize_order_id = :esId LIMIT 1',
            [':esId' => $everysizeOrderId]
        );
    }

    /**
     * @return array{ORDER_ID: string, EVERYSIZE_ORDER_ID: string, PAYPAL_CAPTURE_ID: string, ORIGINAL_EMAIL: string, NUMBER_REPORTED: ?string, TRACKING_CODE: ?string, CARRIER: ?string, CREATED: string}|null
     */
    public function getLink(string $orderId): ?array
    {
        $rows = $this->db->fetchRows(
            'SELECT orders_id AS ORDER_ID, everysize_order_id AS EVERYSIZE_ORDER_ID, paypal_capture_id AS PAYPAL_CAPTURE_ID,
                    original_email AS ORIGINAL_EMAIL, number_reported AS NUMBER_REPORTED,
                    tracking_code AS TRACKING_CODE, carrier AS CARRIER, created_at AS CREATED
             FROM everysize_checkout_order WHERE orders_id = :id LIMIT 1',
            [':id' => $orderId]
        );

        return $rows[0] ?? null;
    }

    public function addOrder(string $orderId, string $everysizeOrderId, string $payPalCaptureId, string $originalEmail = ''): void
    {
        $this->db->execute(
            'INSERT INTO everysize_checkout_order (orders_id, everysize_order_id, paypal_capture_id, original_email, created_at)
             VALUES (:id, :esId, :captureId, :originalEmail, :created)',
            [
                ':id' => $orderId,
                ':esId' => $everysizeOrderId,
                ':captureId' => $payPalCaptureId,
                ':originalEmail' => mb_substr($originalEmail, 0, 255),
                ':created' => date('Y-m-d H:i:s'),
            ]
        );
    }

    public function addOrderItem(string $orderItemId, string $orderId, string $sku): void
    {
        $this->db->execute(
            'INSERT INTO everysize_checkout_order_item (orders_products_id, orders_id, sku) VALUES (:itemId, :orderId, :sku)',
            [':itemId' => $orderItemId, ':orderId' => $orderId, ':sku' => $sku]
        );
    }

    /** Stores a tracking code with its carrier (empty carrier = configured default). */
    public function setTrackingCode(string $orderId, string $trackingCode, string $carrier): void
    {
        $this->db->execute(
            'UPDATE everysize_checkout_order SET tracking_code = :code, carrier = :carrier WHERE orders_id = :id',
            [':code' => mb_substr($trackingCode, 0, 255), ':carrier' => mb_substr($carrier, 0, 31), ':id' => $orderId]
        );
    }

    public function markNumberReported(string $orderId): void
    {
        $this->db->execute(
            'UPDATE everysize_checkout_order SET number_reported = :now WHERE orders_id = :id',
            [':now' => date('Y-m-d H:i:s'), ':id' => $orderId]
        );
    }

    public function isNumberReported(string $orderId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM everysize_checkout_order WHERE orders_id = :id AND number_reported IS NOT NULL',
            [':id' => $orderId]
        ) !== null;
    }

    public function addReport(string $orderId, string $kind, string $sku, string $reason, string $result): void
    {
        $this->db->execute(
            'INSERT INTO everysize_checkout_report (orders_id, kind, sku, reason, result, created_at)
             VALUES (:orderId, :kind, :sku, :reason, :result, :created)',
            [
                ':orderId' => $orderId,
                ':kind' => $kind,
                ':sku' => $sku,
                ':reason' => $reason,
                ':result' => $result,
                ':created' => date('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Note in the order history without a status change (the row carries the
     * order's current status, as Phoenix' update_order does for comments):
     * refunds happen at everysize/PayPal, so the shop would otherwise not show
     * them outside the everysize box.
     */
    public function addHistoryComment(string $orderId, string $comment): void
    {
        $this->db->execute(
            'INSERT INTO orders_status_history (orders_id, orders_status_id, date_added, customer_notified, comments)
             SELECT orders_id, orders_status, :now, 0, :comments FROM orders WHERE orders_id = :id',
            [':now' => date('Y-m-d H:i:s'), ':comments' => $comment, ':id' => $orderId]
        );
    }

    /** History text of a refund, amount in German notation (124,89 EUR). */
    public static function refundComment(string $amount, string $currency, string $refundId, bool $automatic): string
    {
        $text = 'everysize: ';
        $text .= is_numeric($amount)
            ? number_format((float) $amount, 2, ',', '.') . ' ' . ($currency !== '' ? $currency : 'EUR') . ' erstattet'
            : 'erstattet';
        $text .= $automatic ? ' (automatisch nach Storno vor Versand)' : '';

        return $refundId !== '' ? $text . ', PayPal-Erstattung ' . $refundId . '.' : $text . '.';
    }

    /** History text of a return reported from the order box. */
    public static function returnComment(): string
    {
        return 'everysize: Retoure gemeldet.';
    }

    /**
     * The SKUs of an order whose revocation has not been reported yet — the
     * positions a return from the order box still has to report.
     *
     * @return list<string>
     */
    public function getUnrevokedSkus(string $orderId): array
    {
        $rows = $this->db->fetchRows(
            'SELECT i.sku AS SKU FROM everysize_checkout_order_item i
             WHERE i.orders_id = :orderId
               AND NOT EXISTS (
                   SELECT 1 FROM everysize_checkout_report r
                   WHERE r.orders_id = i.orders_id AND r.kind = :revocation AND r.sku = i.sku
               )
             ORDER BY i.everysize_checkout_order_item_id',
            [':orderId' => $orderId, ':revocation' => self::KIND_REVOCATION]
        );

        return array_map(static function (array $row): string {
            return (string) $row['SKU'];
        }, $rows);
    }

    public function hasSentFulfillment(string $orderId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM everysize_checkout_report WHERE orders_id = :orderId AND kind = :kind AND result = :result LIMIT 1',
            [':orderId' => $orderId, ':kind' => self::KIND_FULFILLMENT, ':result' => self::RESULT_SENT]
        ) !== null;
    }

    /**
     * @return list<array{KIND: string, SKU: string, REASON: string, RESULT: string, CREATED: string}>
     */
    public function getReports(string $orderId): array
    {
        return $this->db->fetchRows(
            'SELECT kind AS KIND, sku AS SKU, reason AS REASON, result AS RESULT, created_at AS CREATED
             FROM everysize_checkout_report WHERE orders_id = :orderId ORDER BY created_at, kind, sku',
            [':orderId' => $orderId]
        );
    }

    /**
     * Orders that have ever been in one of the configured "shipped" statuses
     * (orders_status_history — the current status may have moved on before
     * the next run) whose fulfillment has not been reported, with the
     * tracking code stored in the everysize box (first code entered).
     *
     * @return list<array{ORDER_ID: string, EVERYSIZE_ORDER_ID: string, ORDER_NUMBER: string, TRACKING_CODE: string|null, CARRIER: string|null}>
     */
    public function getPendingFulfillments(): array
    {
        $shippingStatuses = array_map('intval', $this->settings->getShippingStatuses());
        if ($shippingStatuses === []) {
            return [];
        }

        // No exclusion of cancellation statuses here: an order that was shipped
        // and cancelled within one sync interval must still report the
        // fulfillment (then the revocation comes as RETOUR in the same run) —
        // otherwise the tracking code would never reach everysize.
        return $this->db->fetchRows(
            sprintf(
                'SELECT o.orders_id AS ORDER_ID, l.everysize_order_id AS EVERYSIZE_ORDER_ID, o.orders_id AS ORDER_NUMBER,
                        l.tracking_code AS TRACKING_CODE, l.carrier AS CARRIER
                 FROM everysize_checkout_order l
                 JOIN orders o ON o.orders_id = l.orders_id
                 WHERE EXISTS (SELECT 1 FROM orders_status_history h WHERE h.orders_id = o.orders_id AND h.orders_status_id IN (%s))
                   AND NOT EXISTS (SELECT 1 FROM everysize_checkout_report r WHERE r.orders_id = o.orders_id AND r.kind = :kind)
                 ORDER BY o.orders_id',
                implode(',', $shippingStatuses)
            ),
            [':kind' => self::KIND_FULFILLMENT]
        );
    }

    /**
     * Orders whose fulfillment was reported since $since and that now carry a
     * tracking code that was never reported: entered after the fulfillment or
     * corrected. Cancelled orders are left out.
     *
     * @return list<array{ORDER_ID: string, EVERYSIZE_ORDER_ID: string, ORDER_NUMBER: string, TRACKING_CODE: string, CARRIER: string|null}>
     */
    public function getPendingTrackingCodes(string $since): array
    {
        $cancellationStatuses = array_map('intval', $this->settings->getCancellationStatuses());
        $cancelCondition = $cancellationStatuses !== []
            ? sprintf(' AND o.orders_status NOT IN (%s) ', implode(',', $cancellationStatuses))
            : '';

        return $this->db->fetchRows(
            sprintf(
                'SELECT o.orders_id AS ORDER_ID, l.everysize_order_id AS EVERYSIZE_ORDER_ID, o.orders_id AS ORDER_NUMBER,
                        TRIM(l.tracking_code) AS TRACKING_CODE, l.carrier AS CARRIER
                 FROM everysize_checkout_order l
                 JOIN orders o ON o.orders_id = l.orders_id
                 JOIN everysize_checkout_report f ON f.orders_id = o.orders_id AND f.kind = :fulfillment AND f.result = :sent
                 WHERE f.created_at >= :since%s
                   AND TRIM(l.tracking_code) <> \'\'
                   AND NOT EXISTS (
                       SELECT 1 FROM everysize_checkout_report tr
                       WHERE tr.orders_id = o.orders_id AND tr.kind = :tracking AND tr.sku = TRIM(l.tracking_code)
                   )
                 ORDER BY o.orders_id',
                $cancelCondition
            ),
            [
                ':since' => $since,
                ':fulfillment' => self::KIND_FULFILLMENT,
                ':sent' => self::RESULT_SENT,
                ':tracking' => self::KIND_TRACKING,
            ]
        );
    }

    /**
     * Items of orders in one of the configured cancellation or return statuses
     * whose revocation has not been reported (Phoenix has no per-position
     * cancellation — the order status is the trigger).
     * IS_RETURN is 1 if the order is in a return status or its fulfillment
     * was reported — the reason is RETOUR then, MERCHANT_DECLINE otherwise.
     *
     * @return list<array{ORDER_ID: string, EVERYSIZE_ORDER_ID: string, ORDER_NUMBER: string, SKU: string, IS_RETURN: int}>
     */
    public function getPendingRevocations(): array
    {
        $statuses = array_map('intval', array_merge(
            $this->settings->getCancellationStatuses(),
            $this->settings->getReturnStatuses()
        ));
        if ($statuses === []) {
            return [];
        }
        $returnStatuses = array_map('intval', $this->settings->getReturnStatuses());
        $returnCondition = $returnStatuses !== [] ? 'o.orders_status IN ('.implode(',', $returnStatuses).')' : '0';

        return $this->db->fetchRows(
            sprintf(
                'SELECT o.orders_id AS ORDER_ID, l.everysize_order_id AS EVERYSIZE_ORDER_ID, o.orders_id AS ORDER_NUMBER,
                        i.sku AS SKU,
                        CASE WHEN %s
                              OR EXISTS (SELECT 1 FROM everysize_checkout_report rf
                                         WHERE rf.orders_id = o.orders_id AND rf.kind = :fulfillment AND rf.result = :sent)
                             THEN 1 ELSE 0 END AS IS_RETURN
                 FROM everysize_checkout_order l
                 JOIN orders o ON o.orders_id = l.orders_id
                 JOIN everysize_checkout_order_item i ON i.orders_id = o.orders_id
                 WHERE o.orders_status IN (%s)
                   AND NOT EXISTS (
                       SELECT 1 FROM everysize_checkout_report r
                       WHERE r.orders_id = o.orders_id AND r.kind = :revocation AND r.sku = i.sku
                   )
                 ORDER BY o.orders_id, i.everysize_checkout_order_item_id',
                $returnCondition,
                implode(',', $statuses)
            ),
            [
                ':fulfillment' => self::KIND_FULFILLMENT,
                ':sent' => self::RESULT_SENT,
                ':revocation' => self::KIND_REVOCATION,
            ]
        );
    }

    /**
     * Orders in one of the configured cancellation statuses whose
     * MERCHANT_DECLINE revocation was reported since $since, that have a
     * capture id (the money was actually charged), and that have no refund of
     * any kind yet (automatic or order box).
     *
     * @return list<array{ORDER_ID: string, EVERYSIZE_ORDER_ID: string, ORDER_NUMBER: string}>
     */
    public function getPendingAutoRefunds(string $since): array
    {
        $cancellationStatuses = array_map('intval', $this->settings->getCancellationStatuses());
        if ($cancellationStatuses === []) {
            return [];
        }

        return $this->db->fetchRows(
            sprintf(
                'SELECT DISTINCT o.orders_id AS ORDER_ID, l.everysize_order_id AS EVERYSIZE_ORDER_ID, o.orders_id AS ORDER_NUMBER
                 FROM everysize_checkout_report r
                 JOIN orders o ON o.orders_id = r.orders_id
                 JOIN everysize_checkout_order l ON l.orders_id = o.orders_id
                 WHERE l.paypal_capture_id <> \'\'
                   AND o.orders_status IN (%s)
                   AND r.kind = :revocation AND r.reason = :decline AND r.result = :sent
                   AND r.created_at >= :since
                   AND NOT EXISTS (
                       -- ANY refund report blocks the auto refund (automatic
                       -- or order box): after a deliberate partial refund the
                       -- rest must not be paid out automatically.
                       SELECT 1 FROM everysize_checkout_report a
                       WHERE a.orders_id = o.orders_id AND a.kind = :refund
                   )
                 ORDER BY o.orders_id',
                implode(',', $cancellationStatuses)
            ),
            [
                ':revocation' => self::KIND_REVOCATION,
                ':decline' => Client::REASON_MERCHANT_DECLINE,
                ':sent' => self::RESULT_SENT,
                ':since' => $since,
                ':refund' => self::KIND_REFUND,
            ]
        );
    }
}
