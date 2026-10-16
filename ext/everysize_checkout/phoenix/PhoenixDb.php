<?php

declare(strict_types=1);

namespace Everysize\Checkout\Phoenix;

use Everysize\Checkout\Db\DbInterface;

/**
 * The module core's database access: raw mysqli with named parameters
 * (the core SQLs use :name placeholders), plus transactions. Values are
 * bound as strings — MySQL casts per column, and the core treats every
 * value as string anyway ("not found" is null, never "" or 0).
 */
class PhoenixDb implements DbInterface
{
    private \mysqli $db;

    private int $transactionDepth = 0;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Connection from the shop defines (admin/includes/configure.php and
     * the CLI boot both set DB_SERVER/DB_SERVER_USERNAME/
     * DB_SERVER_PASSWORD/DB_DATABASE). Never connects on demand — the
     * caller builds one instance per sync run.
     */
    public static function fromShopDefines(): self
    {
        if (!\defined('DB_SERVER') || !\defined('DB_DATABASE')) {
            throw new \RuntimeException('everysize checkout: shop defines (DB_SERVER, DB_DATABASE) are missing — boot the shop first');
        }

        $db = mysqli_init();
        mysqli_options($db, MYSQLI_OPT_INT_AND_FLOAT_NATIVE, false);
        if (!mysqli_real_connect($db, DB_SERVER, DB_SERVER_USERNAME, DB_SERVER_PASSWORD, DB_DATABASE)) {
            throw new \RuntimeException('everysize checkout: database connection failed: '.mysqli_connect_error());
        }
        $db->set_charset('utf8mb4');

        return new self($db);
    }

    /** The raw connection, for shop-side helpers that want it. */
    public function mysqli(): \mysqli
    {
        return $this->db;
    }

    public function fetchValue(string $sql, array $params = []): ?string
    {
        $result = $this->query($sql, $params);
        if ($result === null) {
            return null;
        }
        $row = $result->fetch_row();

        return ($row === false || $row === null) ? null : (string) $row[0];
    }

    public function fetchRows(string $sql, array $params = []): array
    {
        $result = $this->query($sql, $params);
        if ($result === null) {
            return [];
        }
        $rows = [];
        // The interface promises string values (null for NULL); mysqlnd may
        // return native ints/floats despite the option, so cast explicitly.
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $rows[] = array_map(
                static function ($v) {
                    return $v === null ? null : (string) $v;
                },
                $row
            );
        }
        $result->free();

        return $rows;
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->query($sql, $params);
    }

    public function lastInsertId(): ?string
    {
        $id = $this->db->insert_id;

        return $id > 0 ? (string) $id : null;
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->db->begin_transaction();
        }
        ++$this->transactionDepth;
    }

    public function commit(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        --$this->transactionDepth;
        if ($this->transactionDepth === 0) {
            $this->db->commit();
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionDepth === 0) {
            return;
        }
        $this->transactionDepth = 0;
        $this->db->rollback();
    }

    /**
     * Runs the statement with named parameters translated to positional
     * ones (":name" outside string literals; the core SQLs never quote a
     * colon). A statement error is an exception — the import wraps it in
     * a rollback, the sync reports it per order.
     *
     * @return \mysqli_result|null null for statements without a result set (INSERT/UPDATE/DDL)
     */
    private function query(string $sql, array $params): ?\mysqli_result
    {
        [$sql, $values] = $this->toPositional($sql, $params);

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('SQL failed: '.$this->db->error.' -- '.$sql);
        }
        if ($values !== []) {
            $stmt->bind_param(str_repeat('s', \count($values)), ...$values);
        }
        if (!$stmt->execute()) {
            $error = $stmt->error.' -- '.$sql;
            $stmt->close();
            throw new \RuntimeException('SQL failed: '.$error);
        }
        $result = $stmt->get_result();
        $stmt->close();

        // get_result() returns false for statements without a result set (INSERT/UPDATE/DDL).
        return $result === false ? null : $result;
    }

    /**
     * @return array{0: string, 1: list<string>} SQL with ? placeholders and the values in order
     */
    private function toPositional(string $sql, array $params): array
    {
        if ($params === []) {
            return [$sql, []];
        }

        $values = [];
        $sql = preg_replace_callback('/:([A-Za-z_][A-Za-z0-9_]*)/', function (array $m) use ($params, &$values): string {
            if (!\array_key_exists(':'.$m[1], $params)) {
                throw new \RuntimeException('unknown parameter :'.$m[1]);
            }
            $values[] = (string) $params[':'.$m[1]];

            return '?';
        }, $sql);

        return [$sql, $values];
    }
}
