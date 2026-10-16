<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * Order-status lists as stored in the module settings (comma separated).
 * Parsing lives in the core so it is testable without the shop.
 */
final class StatusList
{
    /**
     * '1, 2, 3' → [1, 2, 3]; anything without a valid positive integer is
     * dropped; null/false/empty → [].
     *
     * @param mixed $stored raw stored value
     *
     * @return list<int>
     */
    public static function parse($stored): array
    {
        if ($stored === false || $stored === null) {
            return [];
        }
        $ids = [];
        foreach (explode(',', (string) $stored) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $ids[] = (int) $part;
            }
        }

        return $ids;
    }

    /**
     * @param list<int> $ids
     */
    public static function format(array $ids): string
    {
        return implode(',', array_map('intval', $ids));
    }
}
