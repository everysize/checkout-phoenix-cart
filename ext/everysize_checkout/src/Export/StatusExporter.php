<?php

declare(strict_types=1);

namespace Everysize\Checkout\Export;

use Everysize\Checkout\Api\ApiException;
use Everysize\Checkout\Api\Client;
use Everysize\Checkout\CarrierMapper;
use Everysize\Checkout\OrderLinks;
use Everysize\Checkout\Settings;

/**
 * Reports shipping, cancellations and returns from Gambio to everysize.
 *
 * - Order in one of the configured "shipped" statuses → fulfillment with the
 *   order's first tracking code and the parcel service mapped to an everysize
 *   carrier (the configured carrier as fallback).
 * - Tracking code entered or corrected after that, within TRACKING_DAYS →
 *   the fulfillment once more with the new code. everysize takes a repeated
 *   fulfillment as an update of carrier and code; the customer gets no mail.
 * - Order in a cancellation status before the fulfillment was reported →
 *   revocation MERCHANT_DECLINE; in a return status or cancelled after it →
 *   RETOUR (per position, remainingQuantity 0).
 *
 * Before reporting, the order is fetched from everysize: if it was revoked
 * there in the meantime (PayPal refund, partner backoffice), nothing is sent.
 * A fulfillment would set a revoked order back to COMPLETED, a revocation
 * would overwrite everysize's own reason. A late tracking code goes out only
 * while the order is COMPLETED at everysize, and only if everysize does not
 * have that code yet.
 *
 * A cancellation before shipping (MERCHANT_DECLINE) is refunded in full
 * through everysize afterwards, unless switched off: nothing was shipped, so
 * the customer is owed everything. Returns are left to the merchant (goods
 * first): the order box refunds the amount the merchant chooses and reports
 * the return in the same step (reportReturn). The idempotency key is fixed per order, so a repeated run never
 * refunds twice; transport errors are retried, a refusal is recorded once.
 */
class StatusExporter
{
    /** A tracking code entered later is reported up to this many days after shipping. */
    public const TRACKING_DAYS = 14;

    private Client $client;
    private Settings $settings;
    private OrderLinks $links;

    public function __construct(Client $client, Settings $settings, OrderLinks $links)
    {
        $this->client = $client;
        $this->settings = $settings;
        $this->links = $links;
    }

    /**
     * @return array{fulfillments: int, trackingCodes: int, revocations: int, returns: int, autoRefunds: int, skipped: int, pending: int, errors: string[]}
     */
    public function export(): array
    {
        $result = ['fulfillments' => 0, 'trackingCodes' => 0, 'revocations' => 0, 'returns' => 0, 'autoRefunds' => 0, 'skipped' => 0, 'pending' => 0, 'errors' => []];

        foreach ($this->links->getPendingFulfillments() as $row) {
            ++$result['pending'];
            try {
                if ($this->isRevokedAtEverysize($row['EVERYSIZE_ORDER_ID'])) {
                    $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_FULFILLMENT, '', '', OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    $result['errors'][] = sprintf(
                        'Order %s (%s) is revoked at everysize, shipping not reported',
                        $row['ORDER_NUMBER'],
                        $row['EVERYSIZE_ORDER_ID']
                    );
                    continue;
                }

                $trackingCode = trim((string) ($row['TRACKING_CODE'] ?? ''));
                $this->client->sendFulfillment($row['EVERYSIZE_ORDER_ID'], $this->carrierOf($row), $trackingCode);
                $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_FULFILLMENT, '', '', OrderLinks::RESULT_SENT);
                if ($this->sendsTracking($trackingCode)) {
                    $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SENT);
                }
                ++$result['fulfillments'];
            } catch (ApiException $e) {
                $result['errors'][] = sprintf('Order %s (%s): %s', $row['ORDER_NUMBER'], $row['EVERYSIZE_ORDER_ID'], $e->getMessage());
            }
        }

        // Without a carrier the API takes no tracking code (Client::sendFulfillment): nothing to add later.
        $since = date('Y-m-d H:i:s', time() - self::TRACKING_DAYS * 86400);
        $late = $this->settings->getCarrier() !== '' ? $this->links->getPendingTrackingCodes($since) : [];
        foreach ($late as $row) {
            ++$result['pending'];
            $trackingCode = trim((string) $row['TRACKING_CODE']);
            try {
                $order = $this->client->getOrder($row['EVERYSIZE_ORDER_ID']);
                $status = strtoupper((string) ($order['status'] ?? ''));
                if ($status !== Client::STATUS_COMPLETED) {
                    $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    $result['errors'][] = sprintf(
                        'Order %s (%s) is %s at everysize, tracking code %s not reported',
                        $row['ORDER_NUMBER'],
                        $row['EVERYSIZE_ORDER_ID'],
                        $status !== '' ? $status : 'unknown',
                        $trackingCode
                    );
                    continue;
                }

                if (self::trackingCodeOf($order) === $trackingCode) {
                    $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_KNOWN);
                    continue;
                }

                $this->client->sendFulfillment($row['EVERYSIZE_ORDER_ID'], $this->carrierOf($row), $trackingCode);
                $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_TRACKING, $trackingCode, '', OrderLinks::RESULT_SENT);
                ++$result['trackingCodes'];
            } catch (ApiException $e) {
                $result['errors'][] = sprintf('Order %s (%s), tracking code %s: %s', $row['ORDER_NUMBER'], $row['EVERYSIZE_ORDER_ID'], $trackingCode, $e->getMessage());
            }
        }

        foreach ($this->links->getPendingRevocations() as $row) {
            ++$result['pending'];
            $isReturn = (int) ($row['IS_RETURN'] ?? 0) === 1;
            $reason = $isReturn ? Client::REASON_RETOUR : Client::REASON_MERCHANT_DECLINE;
            try {
                if ($this->isRevokedAtEverysize($row['EVERYSIZE_ORDER_ID'])) {
                    $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_REVOCATION, $row['SKU'], $reason, OrderLinks::RESULT_SKIPPED);
                    ++$result['skipped'];
                    continue;
                }

                $this->client->sendRevocation($row['EVERYSIZE_ORDER_ID'], $row['SKU'], $reason);
                $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_REVOCATION, $row['SKU'], $reason, OrderLinks::RESULT_SENT);
                ++$result[$isReturn ? 'returns' : 'revocations'];
            } catch (ApiException $e) {
                $result['errors'][] = sprintf('Order %s (%s), %s: %s', $row['ORDER_NUMBER'], $row['EVERYSIZE_ORDER_ID'], $row['SKU'], $e->getMessage());
            }
        }

        // From when the setting was switched on, and no older than TRACKING_DAYS (a lasting outage is not retried forever).
        $since = $this->settings->getAutoRefundSince();
        $since = $since !== '' ? max($since, date('Y-m-d H:i:s', time() - self::TRACKING_DAYS * 86400)) : '';
        $declined = $this->settings->isAutoRefundDecline() && $since !== '' ? $this->links->getPendingAutoRefunds($since) : [];
        foreach ($declined as $row) {
            ++$result['pending'];
            try {
                $refund = $this->client->refund($row['EVERYSIZE_ORDER_ID'], null, OrderLinks::AUTO_REFUND.'-'.$row['EVERYSIZE_ORDER_ID']);
                $status = strtoupper((string) ($refund['status'] ?? ''));
                if ($status === 'ALREADY_REFUNDED') {
                    $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, $status, OrderLinks::RESULT_KNOWN);
                    continue;
                }
                $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, (string) ($refund['refundId'] ?? $status), OrderLinks::RESULT_SENT);
                $this->links->addHistoryComment($row['ORDER_ID'], OrderLinks::refundComment(
                    (string) ($refund['amount'] ?? ''),
                    (string) ($refund['currency'] ?? ''),
                    (string) ($refund['refundId'] ?? ''),
                    true
                ));
                ++$result['autoRefunds'];
            } catch (ApiException $e) {
                // No answer or a server error: try again next run. A refusal (not refundable, PayPal said no) stays as it is.
                $retry = $e->getStatus() === 0 || ($e->getStatus() >= 500 && $e->getReason() !== 'PAYPAL_REFUND_FAILED');
                if (!$retry) {
                    $this->links->addReport($row['ORDER_ID'], OrderLinks::KIND_REFUND, OrderLinks::AUTO_REFUND, mb_substr($e->getReason() !== '' ? $e->getReason() : 'HTTP '.$e->getStatus(), 0, 255), OrderLinks::RESULT_SKIPPED);
                }
                $result['errors'][] = sprintf(
                    'Order %s (%s): automatic refund after cancellation failed%s: %s',
                    $row['ORDER_NUMBER'],
                    $row['EVERYSIZE_ORDER_ID'],
                    $retry ? ' (retried next run)' : ', please refund in the order box "everysize"',
                    $e->getMessage()
                );
            }
        }

        return $result;
    }

    /**
     * The return of a shipped order, reported from the order box: every
     * position not revoked yet goes out as RETOUR. The caller refunds AFTER
     * this — a full refund may revoke the order at everysize, and a revoked
     * order takes no return any more. Already revoked at everysize: nothing
     * is sent, the positions are recorded as skipped.
     *
     * @return array{returns: int, skipped: int}
     *
     * @throws ApiException on API errors (the positions sent so far are recorded)
     */
    public function reportReturn(string $orderId, string $everysizeOrderId): array
    {
        $result = ['returns' => 0, 'skipped' => 0];
        $skus = $this->links->getUnrevokedSkus($orderId);
        if ($skus === []) {
            return $result;
        }

        if ($this->isRevokedAtEverysize($everysizeOrderId)) {
            foreach ($skus as $sku) {
                $this->links->addReport($orderId, OrderLinks::KIND_REVOCATION, $sku, Client::REASON_RETOUR, OrderLinks::RESULT_SKIPPED);
                ++$result['skipped'];
            }

            return $result;
        }

        foreach ($skus as $sku) {
            $this->client->sendRevocation($everysizeOrderId, $sku, Client::REASON_RETOUR);
            $this->links->addReport($orderId, OrderLinks::KIND_REVOCATION, $sku, Client::REASON_RETOUR, OrderLinks::RESULT_SENT);
            ++$result['returns'];
        }
        $this->links->addHistoryComment($orderId, OrderLinks::returnComment());

        return $result;
    }

    private function isRevokedAtEverysize(string $everysizeOrderId): bool
    {
        $order = $this->client->getOrder($everysizeOrderId);

        return strtoupper((string) ($order['status'] ?? '')) === Client::STATUS_REVOKED;
    }

    /**
     * Carrier for the fulfillment: the parcel service of the tracking code
     * mapped to an everysize carrier (CarrierMapper), otherwise the
     * configured default.
     *
     * @param array<string, string|null> $row
     */
    private function carrierOf(array $row): string
    {
        return CarrierMapper::map((string) ($row['CARRIER'] ?? ''), $this->settings->getCarrier());
    }

    /**
     * The tracking code everysize has for an order: fulfillment.tracking is
     * {code, carrier}, or an empty list without one.
     *
     * @param array<string, mixed> $order
     */
    private static function trackingCodeOf(array $order): string
    {
        $tracking = $order['fulfillment']['tracking'] ?? null;
        if (\is_array($tracking) && isset($tracking[0]) && \is_array($tracking[0])) {
            $tracking = $tracking[0];
        }

        return \is_array($tracking) && isset($tracking['code']) ? trim((string) $tracking['code']) : '';
    }

    /** The API takes a tracking code only together with a carrier (Client::sendFulfillment). */
    private function sendsTracking(string $trackingCode): bool
    {
        return $trackingCode !== '' && $this->settings->getCarrier() !== '';
    }
}
