<?php

namespace Hub\UltraCore\Support;

use Hub\UltraCore\Ven\LedgerConnection;
use PDO;

/**
 * LedgerConnection over SQLite, standing in for MySQL in tests. `UPDATE/DELETE ... LIMIT 1` is accepted by MySQL
 * only, so the suffix is dropped here. A test can make statements fail with failWhen().
 */
class SqliteLedgerConnection implements LedgerConnection
{
    /** @var PDO */
    private $pdo;

    /** @var bool behave like InnoDB (transactions) or MyISAM (none) */
    private $transactional;

    /** @var callable[] function(string $sql, array $params): bool, true = fail this statement */
    private $failures = array();

    /** @var bool */
    public $failCommit = false;

    /** @var bool */
    public $failRollback = false;

    /** @var string[] every statement run, for assertions */
    public $log = array();

    private $lastError = '';

    public function __construct(PDO $pdo, $transactional = false)
    {
        $this->pdo = $pdo;
        $this->transactional = (bool)$transactional;
    }

    /**
     * @param callable $matcher function(string $sql, array $params): bool
     */
    public function failWhen(callable $matcher)
    {
        $this->failures[] = $matcher;
    }

    public function execute($sql, array $params = array())
    {
        $stmt = $this->run($sql, $params);

        return $stmt === false ? false : $stmt->rowCount();
    }

    public function insert($sql, array $params = array())
    {
        $stmt = $this->run($sql, $params);
        if ($stmt === false || $stmt->rowCount() !== 1) {
            return false;
        }

        return (int)$this->pdo->lastInsertId();
    }

    public function fetchValue($sql, array $params = array())
    {
        $stmt = $this->run($sql, $params);
        if ($stmt === false) {
            return null;
        }
        $value = $stmt->fetchColumn();

        return $value === false ? null : $value;
    }

    public function beginIfTransactional()
    {
        if (!$this->transactional || $this->pdo->inTransaction()) {
            return false;
        }

        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        if ($this->failCommit) {
            $this->pdo->rollBack();
            $this->lastError = 'commit failed (test)';

            return false;
        }

        return $this->pdo->commit();
    }

    public function rollBack()
    {
        if ($this->failRollback) {
            $this->lastError = 'rollback failed (test)';

            return false;
        }

        return $this->pdo->inTransaction() ? $this->pdo->rollBack() : false;
    }

    public function lastError()
    {
        return $this->lastError;
    }

    private function run($sql, array $params)
    {
        $this->lastError = '';
        $this->log[] = $sql;
        foreach ($this->failures as $matcher) {
            if ($matcher($sql, $params)) {
                $this->lastError = 'injected failure';

                return false;
            }
        }
        if (preg_match('/^\s*(UPDATE|DELETE)\b/i', $sql)) {
            $sql = preg_replace('/\s+LIMIT 1$/', '', $sql);
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            foreach (array_values($params) as $i => $value) {
                $type = is_int($value) || is_bool($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
                $stmt->bindValue($i + 1, is_bool($value) ? (int)$value : $value, $type);
            }
            $stmt->execute();
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();

            return false;
        }

        return $stmt;
    }
}
