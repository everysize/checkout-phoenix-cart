<?php

declare(strict_types=1);

namespace Everysize\Checkout;

/**
 * Protocol of sync runs, shown in the admin. Only runs that moved something
 * or failed are recorded — with a run every 5 minutes, "nothing to do"
 * entries would bury the relevant ones. The Gambio layer implements this
 * (plus the list/count methods for the admin page).
 */
interface Protocol
{
    public const RETENTION_DAYS = 31;

    public function add(string $title, string $message, bool $success): void;

    public function cleanup(int $days = self::RETENTION_DAYS): void;
}
