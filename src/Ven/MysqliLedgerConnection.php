<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Ven;

use mysqli;

/**
 * LedgerConnection over the main Hub Culture mysqli write connection.
 *
 * A transaction is opened only when both `venbalance` and `transactions` are InnoDB (checked once in
 * information_schema) and the connection is not already inside a transaction (MariaDB's @@in_transaction). When the
 * server can't say, no transaction is opened, so a caller's own transaction is never committed early. Without a
 * transaction, the ledger's conditional writes and reversals keep MyISAM safe.
 */
class MysqliLedgerConnection implements LedgerConnection
{
    /** @var mysqli */
    private $db;

    /** @var bool|null null = detect */
    private $useTransactions;

    /** @var bool|null detected engines, cached */
    private $tablesTransactional;

    /** @var string */
    private $lastError = '';

    /**
     * @param mysqli    $db
     * @param bool|null $useTransactions null detects (default); false never opens one; true skips the engine check
     *                                   (still never opens one inside another transaction)
     */
    public function __construct(mysqli $db, $useTransactions = null)
    {
        $this->db = $db;
        $this->useTransactions = $useTransactions;
    }

    public function execute($sql, array $params = array())
    {
        $stmt = $this->run($sql, $params);
        if ($stmt === false) {
            return false;
        }
        $rows = (int)$stmt->affected_rows;
        $stmt->close();

        return $rows;
    }

    public function insert($sql, array $params = array())
    {
        $stmt = $this->run($sql, $params);
        if ($stmt === false) {
            return false;
        }
        $id = (int)$stmt->insert_id;
        $rows = (int)$stmt->affected_rows;
        $stmt->close();

        return ($rows === 1 && $id > 0) ? $id : false;
    }

    public function fetchValue($sql, array $params = array())
    {
        $stmt = $this->run($sql, $params);
        if ($stmt === false) {
            return null;
        }
        $value = null;
        try {
            $stmt->bind_result($value);
            if (!$stmt->fetch()) {
                $value = null;
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            $value = null;
        }
        $stmt->close();

        return $value;
    }

    public function beginIfTransactional()
    {
        if ($this->useTransactions === false) {
            return false;
        }
        if ($this->useTransactions === null && !$this->tablesAreTransactional()) {
            return false;
        }
        // Never open a transaction over one the caller already has: BEGIN would commit theirs.
        $inTransaction = $this->fetchValue('SELECT @@in_transaction');
        if ($inTransaction === null || (int)$inTransaction !== 0) {
            return false;
        }
        try {
            return (bool)$this->db->begin_transaction();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    public function commit()
    {
        try {
            return (bool)$this->db->commit();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    public function rollBack()
    {
        try {
            return (bool)$this->db->rollback();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return false;
        }
    }

    public function lastError()
    {
        return $this->lastError;
    }

    /**
     * @return bool
     */
    private function tablesAreTransactional()
    {
        if ($this->tablesTransactional === null) {
            $innodb = $this->fetchValue(
                "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('venbalance', 'transactions') AND ENGINE = 'InnoDB'"
            );
            if ($innodb === null) {
                return false; // unknown: don't cache it, and don't rely on a rollback
            }
            $this->tablesTransactional = ((int)$innodb === 2);
        }

        return $this->tablesTransactional;
    }

    /**
     * @param string $sql
     * @param array  $params
     *
     * @return \mysqli_stmt|false an executed statement
     */
    private function run($sql, array $params)
    {
        $this->lastError = '';
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                $this->lastError = (string)$this->db->error;

                return false;
            }
            if (!empty($params)) {
                $types = '';
                $values = array();
                foreach (array_values($params) as $value) {
                    if (is_int($value) || is_bool($value)) {
                        $types .= 'i';
                        $values[] = (int)$value;
                    } elseif (is_float($value)) {
                        $types .= 'd';
                        $values[] = $value;
                    } else {
                        $types .= 's';
                        $values[] = (string)$value;
                    }
                }
                $stmt->bind_param($types, ...$values);
            }
            if (!$stmt->execute()) {
                $this->lastError = (string)$stmt->error;
                $stmt->close();

                return false;
            }

            return $stmt;
        } catch (\Throwable $e) {
            // mysqli in exception mode (the PHP 8.1+ default) throws mysqli_sql_exception
            $this->lastError = $e->getMessage();

            return false;
        }
    }
}
