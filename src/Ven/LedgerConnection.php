<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Ven;

/**
 * The few database operations the Ven ledger needs. Implementations never throw: a database error is reported as
 * false (or null), so the ledger can always decide what to put back.
 *
 * Parameters are positional (?). Integers bind as integers, floats as doubles, anything else as strings.
 */
interface LedgerConnection
{
    /**
     * @param string $sql
     * @param array  $params
     *
     * @return int|false rows changed, or false on a database error
     */
    public function execute($sql, array $params = array());

    /**
     * @param string $sql
     * @param array  $params
     *
     * @return int|false the new row id, or false on a database error
     */
    public function insert($sql, array $params = array());

    /**
     * @param string $sql
     * @param array  $params
     *
     * @return mixed|null first column of the first row; null when there is no row or on a database error
     */
    public function fetchValue($sql, array $params = array());

    /**
     * Opens a database transaction when the Ven tables can roll back (InnoDB) and nobody else has one open on this
     * connection. Returns false otherwise, and the ledger then reverses its own writes.
     *
     * @return bool true when a transaction is now open
     */
    public function beginIfTransactional();

    /**
     * @return bool
     */
    public function commit();

    /**
     * @return bool
     */
    public function rollBack();

    /**
     * @return string the last database error, or ''
     */
    public function lastError();
}
