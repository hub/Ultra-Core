<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\MatchEngine\Settlement;

use Doctrine\DBAL\Connection;

/**
 * A MySQL / MariaDB named lock (GET_LOCK) on the given connection, held for the whole match engine run. It doesn't
 * wait: a second run that finds it taken does nothing. If the lock can't be read at all, the run doesn't start.
 *
 * Belt and braces: the per-order claims in SettlementGuard still stop a double settlement if the lock is ever lost
 * (for example a reconnect mid-run).
 */
class MysqlNamedLock implements RunLock
{
    const DEFAULT_NAME = 'hub_ultra_match_engine';

    /** @var Connection */
    private $db;

    /** @var string */
    private $name;

    /** @var bool */
    private $held = false;

    /**
     * @param Connection $db
     * @param string     $name
     */
    public function __construct(Connection $db, $name = self::DEFAULT_NAME)
    {
        $this->db = $db;
        $this->name = (string)$name;
    }

    public function acquire()
    {
        try {
            $this->held = ((string)$this->db->fetchOne('SELECT GET_LOCK(?, 0)', array($this->name)) === '1');
        } catch (\Throwable $e) {
            $this->held = false;
        }

        return $this->held;
    }

    public function release()
    {
        if (!$this->held) {
            return;
        }
        try {
            $this->db->fetchOne('SELECT RELEASE_LOCK(?)', array($this->name));
        } catch (\Throwable $e) {
            // the lock goes with the connection anyway
        }
        $this->held = false;
    }
}
