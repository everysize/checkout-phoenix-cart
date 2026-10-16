<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * A secret typed into the settings form that consists only of masking
 * characters (bullets, asterisks, middle dots, whitespace) is a browser or
 * password-manager placeholder, not a credential — treat it as "empty, keep
 * the stored one". The empty string itself counts as masked, so a single
 * check covers both cases.
 */
final class Credentials
{
    private const MASKED_PATTERN = '/^[•*·\s]*$/';

    public static function isMaskedPlaceholder(string $value): bool
    {
        return preg_match(self::MASKED_PATTERN, $value) === 1;
    }
}
