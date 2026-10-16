<?php

declare(strict_types=1);

namespace Everysize\Checkout\Db;

/**
 * Slim database access for the module core: the Gambio layer implements it
 * with the shop's DB layer (parameterized queries only), the tests with a
 * fake. "Not found" must be passed on as null, never as "" or 0.
 */
interface DbInterface
{
    /**
     * First value of the first row; null if the query finds nothing.
     */
    public function fetchValue(string $sql, array $params = []): ?string;

    /**
     * @return list<array<string, string|null>> rows keyed by column name (upper case, as returned)
     */
    public function fetchRows(string $sql, array $params = []): array;

    public function execute(string $sql, array $params = []): void;

    /** Auto increment id of the last INSERT. */
    public function lastInsertId(): ?string;
}
