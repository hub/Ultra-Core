<?php

namespace Hub\UltraCore\Support;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PDO;

/**
 * One in-memory SQLite database with the tables the Ven ledger and the match engine touch, shaped like hubapp's
 * (the order statuses and claim columns as hub/Hub Version20261119120000 leaves them). The DBAL connection
 * (orders, settlement keys) and the Ven ledger share it, as they share hubapp in production.
 */
class LedgerFixture
{
    /** @var Connection */
    public $dbal;

    /** @var PDO */
    public $pdo;

    public function __construct()
    {
        $this->dbal = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->pdo = $this->dbal->getNativeConnection();
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('CREATE TABLE venbalance (user INTEGER NOT NULL, balance NUMERIC NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, `from` INTEGER, `to` INTEGER, amount NUMERIC, timestamp INTEGER, message TEXT, hide INTEGER, special TEXT, type TEXT)');
        $this->pdo->exec("CREATE TABLE ultra_custom_buy_sell_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, asset_id INTEGER, type TEXT,
            status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('processed','rejected','pending','processing','review')),
            transaction_currency TEXT DEFAULT 'Ven', offering_rate NUMERIC, market_rate NUMERIC, asset_amount NUMERIC,
            settled_amount_so_far NUMERIC DEFAULT 0, offer_expire_date TEXT, num_match_attempts INTEGER DEFAULT 0,
            notes TEXT, claim_token TEXT NULL, claimed_at TEXT NULL, created_at TEXT, updated_at TEXT)");
        $this->pdo->exec('CREATE TABLE ultra_custom_buy_sell_order_settlements (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, matched_order_id INTEGER, asset_amount NUMERIC, created_at TEXT)');
        $this->pdo->exec("CREATE TABLE ultra_order_settlement_keys (
            settlement_key TEXT PRIMARY KEY, kind TEXT NOT NULL, order_id INTEGER NOT NULL, matched_order_id INTEGER NULL,
            status TEXT NOT NULL CHECK (status IN ('claimed','completed','failed','review')), claim_token TEXT NOT NULL,
            progress TEXT NULL, note TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)");
    }

    /**
     * @param bool $transactional InnoDB-like (true) or MyISAM-like (false)
     *
     * @return SqliteLedgerConnection
     */
    public function ledgerConnection($transactional = false)
    {
        return new SqliteLedgerConnection($this->pdo, $transactional);
    }

    public function account($userId, $balance)
    {
        $this->pdo->prepare('INSERT INTO venbalance (user, balance) VALUES (?, ?)')->execute([(int)$userId, $balance]);
    }

    public function balance($userId)
    {
        $stmt = $this->pdo->prepare('SELECT balance FROM venbalance WHERE user = ?');
        $stmt->execute([(int)$userId]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : round((float)$value, 2);
    }

    public function totalVen()
    {
        return round((float)$this->pdo->query('SELECT SUM(balance) FROM venbalance')->fetchColumn(), 2);
    }

    public function transactionCount()
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    }

    /**
     * @return int the order id
     */
    public function order($userId, $assetId, $type, $offeringRate, $amount, $attempts = 3, $settledSoFar = 0)
    {
        $this->pdo->prepare("INSERT INTO ultra_custom_buy_sell_orders (user_id, asset_id, type, status, offering_rate, market_rate, asset_amount, settled_amount_so_far, num_match_attempts, created_at) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $assetId, $type, $offeringRate, $offeringRate, $amount, $settledSoFar, $attempts, date('Y-m-d H:i:s')]);

        return (int)$this->pdo->lastInsertId();
    }

    public function orderRow($orderId)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ultra_custom_buy_sell_orders WHERE id = ?');
        $stmt->execute([(int)$orderId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function keyRow($key)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ultra_order_settlement_keys WHERE settlement_key = ?');
        $stmt->execute([$key]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /** Makes every claim look older than the stale threshold, as after a crash a while ago. */
    public function ageClaims()
    {
        $this->pdo->exec("UPDATE ultra_custom_buy_sell_orders SET claimed_at = '2000-01-01 00:00:00' WHERE claimed_at IS NOT NULL");
    }
}
