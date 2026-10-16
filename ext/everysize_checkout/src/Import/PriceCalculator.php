<?php

declare(strict_types=1);

namespace Everysize\Checkout\Import;

use Everysize\Checkout\Api\Amount;

/**
 * Turns the gross amounts everysize charged into the net/VAT figures Gambio
 * stores on an order. everysize sends gross prices only; the VAT rate comes
 * from the shop (the tax rate of the product's tax class for the delivery
 * country).
 */
final class PriceCalculator
{
    /**
     * @param array<int, array{gross: float, quantity: int, vat: float}> $items unit gross price, quantity, VAT rate
     *
     * @return array{
     *     items: array<int, array{unitGross: float, unitNet: float, totalGross: float, totalNet: float, totalVat: float, vat: float}>,
     *     totalGross: float,
     *     totalNet: float,
     *     vatGroups: array<string, float>,
     *     deliveryCost: float,
     *     deliveryVat: float,
     *     totalOrderSum: float
     * }
     */
    public static function calculate(array $items, float $shippingGross): array
    {
        $result = [
            'items' => [],
            'totalGross' => 0.0,
            'totalNet' => 0.0,
            'vatGroups' => [],
            'deliveryCost' => round($shippingGross, 2),
            'deliveryVat' => 0.0,
            'totalOrderSum' => 0.0,
        ];

        foreach ($items as $key => $item) {
            $vat = (float) $item['vat'];
            $unitGross = round($item['gross'], 2);
            $totalGross = round($unitGross * $item['quantity'], 2);
            $totalNet = self::net($totalGross, $vat);
            $totalVat = round($totalGross - $totalNet, 2);

            $result['items'][$key] = [
                'unitGross' => $unitGross,
                'unitNet' => self::net($unitGross, $vat),
                'totalGross' => $totalGross,
                'totalNet' => $totalNet,
                'totalVat' => $totalVat,
                'vat' => $vat,
            ];

            $result['totalGross'] += $totalGross;
            $result['totalNet'] += $totalNet;
            $rate = self::rateKey($vat);
            $result['vatGroups'][$rate] = round(($result['vatGroups'][$rate] ?? 0.0) + $totalVat, 2);
            // Shipping carries the VAT rate of the items; an everysize order has one item, with several the highest.
            $result['deliveryVat'] = max($result['deliveryVat'], $vat);
        }

        $result['totalGross'] = round($result['totalGross'], 2);
        $result['totalNet'] = round($result['totalNet'], 2);
        $result['totalOrderSum'] = round($result['totalGross'] + $result['deliveryCost'], 2);
        krsort($result['vatGroups'], \SORT_NUMERIC);

        return $result;
    }

    /**
     * Compares the order total built from the line items (and shipping) with
     * the gross price everysize charged. Returns a warning text or null; a
     * difference over one cent is a warning, the import continues.
     *
     * @param string|int|float|null $grossPrice grossPrice field of the everysize order
     */
    public static function checkTotal($grossPrice, float $totalOrderSum, string $everysizeOrderId = ''): ?string
    {
        if ($grossPrice === null) {
            return null;
        }

        $everysizeTotal = Amount::parse($grossPrice, 'grossPrice');
        if (abs($everysizeTotal - $totalOrderSum) <= 0.009) {
            return null;
        }

        return sprintf(
            '%s: total %.2f differs from everysize grossPrice %.2f',
            $everysizeOrderId,
            $totalOrderSum,
            $everysizeTotal
        );
    }

    private static function net(float $gross, float $vat): float
    {
        return round($gross / (1 + $vat / 100), 2);
    }

    private static function rateKey(float $vat): string
    {
        return rtrim(rtrim(number_format($vat, 2, '.', ''), '0'), '.');
    }
}
