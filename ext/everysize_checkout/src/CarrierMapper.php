<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * Maps the parcel service name Gambio stores with a tracking code
 * (orders_parcel_tracking_codes.parcel_service_name, e.g. "MyHermes",
 * "Hermes HSI", "DHL Paket") to one of the carriers everysize knows.
 * Anything that does not map to one of them falls back to the configured
 * default carrier.
 */
final class CarrierMapper
{
    /** Carriers listed in the everysize API documentation; the API accepts any value. */
    public const CARRIERS = [
        'Cargo', 'Der Courier', 'Deutsche Post', 'DHL', 'DPD', 'eparcel', 'FedEx', 'GdSK', 'GLS', 'GO!',
        'Hermes', 'iloxx', 'Midway', 'Noxxs Logistic', 'paket.ag', 'primeMail', 'TOMBA', 'UPS',
    ];

    /** Lower-case substrings → carrier, checked in this order (specific ones first). */
    private const PATTERNS = [
        'Hermes' => ['myhermes', 'hermes'],
        'Deutsche Post' => ['deutsche post', 'deutschepost'],
        'DHL' => ['dhl'],
        'DPD' => ['dpd'],
        'FedEx' => ['fedex', 'fed ex'],
        'GLS' => ['gls'],
        'UPS' => ['ups'],
        'iloxx' => ['iloxx'],
        'Der Courier' => ['der courier', 'courier'],
        'Cargo' => ['cargo'],
        'eparcel' => ['eparcel'],
        'GdSK' => ['gdsk'],
        'GO!' => ['go!'],
        'Midway' => ['midway'],
        'Noxxs Logistic' => ['noxxs'],
        'paket.ag' => ['paket.ag', 'paketag'],
        'primeMail' => ['primemail'],
        'TOMBA' => ['tomba'],
    ];

    /**
     * Case-insensitive substring match; an empty name or an unknown parcel
     * service (Shipcloud, an own name) returns the default.
     */
    public static function map(string $gambioName, string $default): string
    {
        $name = mb_strtolower(trim($gambioName));
        if ($name === '') {
            return $default;
        }

        foreach (self::PATTERNS as $carrier => $needles) {
            foreach ($needles as $needle) {
                if (strpos($name, $needle) !== false) {
                    return $carrier;
                }
            }
        }

        return $default;
    }
}
