<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * Module settings: credentials, mapping and behaviour. The Gambio layer
 * implements this against the shop database; the core (StatusExporter, Sync)
 * and the tests only know this interface.
 */
interface Settings
{
    public function getClientId(): string;

    public function getClientSecret(): string;

    /** Without credentials the module makes no API calls at all. */
    public function isConfigured(): bool;

    /** Carrier reported to everysize when the parcel service does not map to one (default DHL). */
    public function getCarrier(): string;

    /** 'true': refund the full amount when an order is cancelled before shipping. */
    public function isAutoRefundDecline(): bool;

    /** Cancellations reported from this time on are refunded automatically (never older ones). */
    public function getAutoRefundSince(): string;

    /**
     * Gambio order status ids that mean "shipped" (multi select); an order in
     * one of these gets its fulfillment reported.
     *
     * @return list<int>
     */
    public function getShippingStatuses(): array;

    /**
     * Gambio order status ids that mean "cancelled".
     *
     * @return list<int>
     */
    public function getCancellationStatuses(): array;

    /**
     * Optional Gambio order status ids that mean "returned"; revocations from
     * these are reported as RETOUR even without a reported fulfillment.
     *
     * @return list<int>
     */
    public function getReturnStatuses(): array;
}
