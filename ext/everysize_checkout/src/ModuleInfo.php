<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * The module's own identity. This is the single source of truth for the
 * version in code — GXModule.json and composer.json must match it
 * (checked by ModuleVersionTest).
 */
final class ModuleInfo
{
    public const VERSION = '1.0.0';

    /**
     * Phoenix's own version as reported to everysize (ERP-Shop-System-Version):
    * the bare number like every plugin sends it ("1.1.0.8"); Phoenix stores it
    * plainly in includes/version.php. The system name goes separately in
    * ERP-Shop-System.
     */
    public static function shopSystemVersion(?string $installedVersion): string
    {
        $version = ltrim(trim((string) $installedVersion), 'vV');

        return $version !== '' ? $version : 'unknown';
    }
}
