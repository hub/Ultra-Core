<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\MatchEngine\Settlement;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Hub\UltraCore\MatchEngine\Order\Orders;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Makes the match engine idempotent per order: nothing moves money for an order it hasn't claimed, and every
 * settlement is recorded under a key, so a re-run after a crash completes it or stops for a human; it never pays
 * again.
 *
 * Two records, both written with conditional statements that must change exactly one row (safe on MyISAM too):
 *
 *   ultra_custom_buy_sell_orders  status pending -> processing (claim_token, claimed_at) -> processed | rejected,
 *                                 or review when the money's state is uncertain. Orders the issuer path or the
 *                                 system buy-back settle are claimed here, so the order book and a second run skip
 *                                 them.
 *   ultra_order_settlement_keys   one row per settlement: `order:{id}` (issuer purchase, system buy-back) or
 *                                 `pair:{settlement id}` (an order-book match). The primary key makes the claim
 *                                 atomic. Status:
 *                                   claimed    money may be moving; a stale one is never retried (review)
 *                                   completed  every leg moved; a re-run only marks the order processed
 *                                   failed     nothing moved (or all of it was put back); may be claimed again
 *                                   review     needs a human; never retried
 *
 * Schema: hub/Hub db-migrations Version20261119120000. Without it every claim fails and nothing is settled.
 */
class SettlementGuard
{
    const ORDERS_TABLE = 'ultra_custom_buy_sell_orders';
    const KEYS_TABLE = 'ultra_order_settlement_keys';

    const KEY_CLAIMED = 'claimed';
    const KEY_COMPLETED = 'completed';
    const KEY_FAILED = 'failed';
    const KEY_REVIEW = 'review';

    /** beginSettlement() outcomes */
    const CLAIMED = 'claimed';
    const ALREADY_COMPLETED = 'already-completed';
    const BLOCKED = 'blocked';
    const ERROR = 'error';

    /** A claim older than this, found while holding the run lock, is left from a crashed run. */
    const STALE_AFTER_SECONDS = 900;

    /** @var Connection */
    private $db;

    /** @var LoggerInterface */
    private $logger;

    /**
     * @param Connection           $db
     * @param LoggerInterface|null $logger
     */
    public function __construct(Connection $db, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?: new NullLogger();
    }

    /**
     * Whether the claim columns and the settlement key table exist (hub/Hub Version20261119120000). Without them
     * nothing can be claimed, so the match engine must not even match: a matched pair it can't settle exactly
     * once would be left unpaid.
     *
     * @return bool
     */
    public function isReady()
    {
        try {
            $this->db->fetchOne('SELECT `claim_token`, `claimed_at` FROM `' . self::ORDERS_TABLE . '` WHERE 1 = 0');
            $this->db->fetchOne('SELECT `settlement_key`, `status`, `claim_token`, `progress`, `note` FROM `' . self::KEYS_TABLE . '` WHERE 1 = 0');
        } catch (\Throwable $e) {
            $this->logger->critical('The match engine settlement schema is missing (run hub/Hub db-migrations Version20261119120000): ' . $e->getMessage(), [__CLASS__]);

            return false;
        }

        return true;
    }

    /**
     * @return string a random claim token for one settlement attempt
     */
    public function newToken()
    {
        return bin2hex(random_bytes(16));
    }

    // ------------------------------------------------------------------ orders

    /**
     * pending -> processing, for this token only.
     *
     * @param int    $orderId
     * @param string $token
     *
     * @return bool true when this call claimed the order (exactly one row changed)
     */
    public function claimOrder($orderId, $token)
    {
        return $this->exactlyOne(
            'UPDATE `' . self::ORDERS_TABLE . '` SET `status` = ?, `claim_token` = ?, `claimed_at` = ? WHERE `id` = ? AND `status` = ?',
            array(Orders::STATUS_PROCESSING, $token, $this->now(), (int)$orderId, Orders::STATUS_PENDING),
            "claim order {$orderId}"
        );
    }

    /**
     * processing -> pending (nothing moved; the next run may try again).
     */
    public function releaseOrder($orderId, $token)
    {
        return $this->exactlyOne(
            'UPDATE `' . self::ORDERS_TABLE . '` SET `status` = ?, `claim_token` = NULL, `claimed_at` = NULL WHERE `id` = ? AND `status` = ? AND `claim_token` = ?',
            array(Orders::STATUS_PENDING, (int)$orderId, Orders::STATUS_PROCESSING, $token),
            "release order {$orderId}"
        );
    }

    /**
     * processing -> processed.
     */
    public function finishOrder($orderId, $token, $notes = '')
    {
        return $this->exactlyOne(
            'UPDATE `' . self::ORDERS_TABLE . '` SET `status` = ?, `notes` = ? WHERE `id` = ? AND `status` = ? AND `claim_token` = ?',
            array(Orders::STATUS_PROCESSED, (string)$notes, (int)$orderId, Orders::STATUS_PROCESSING, $token),
            "finish order {$orderId}"
        );
    }

    /**
     * processing -> rejected (nothing moved).
     */
    public function rejectOrder($orderId, $token, $notes)
    {
        $done = $this->exactlyOne(
            'UPDATE `' . self::ORDERS_TABLE . '` SET `status` = ?, `notes` = ? WHERE `id` = ? AND `status` = ? AND `claim_token` = ?',
            array(Orders::STATUS_REJECTED, (string)$notes, (int)$orderId, Orders::STATUS_PROCESSING, $token),
            "reject order {$orderId}"
        );
        if ($done) {
            $this->logger->info(sprintf("Marked the order [%d] as 'rejected'", $orderId), array(__CLASS__));
        }

        return $done;
    }

    /**
     * -> review: the order stays out of every automatic path until a human sets it.
     *
     * @param int         $orderId
     * @param string|null $token null for an order found stale
     * @param string      $notes
     *
     * @return bool
     */
    public function flagOrder($orderId, $token, $notes)
    {
        $sql = 'UPDATE `' . self::ORDERS_TABLE . '` SET `status` = ?, `notes` = ? WHERE `id` = ? AND `status` IN (?, ?)';
        $params = array(Orders::STATUS_REVIEW, 'REVIEW: ' . $notes, (int)$orderId, Orders::STATUS_PROCESSING, Orders::STATUS_PENDING);
        if ($token !== null) {
            $sql .= ' AND (`claim_token` = ? OR `claim_token` IS NULL)';
            $params[] = $token;
        }
        $done = $this->exactlyOne($sql, $params, "flag order {$orderId} for review");
        $this->critical("order {$orderId} flagged for review: {$notes}", array('order_id' => (int)$orderId));

        return $done;
    }

    // ------------------------------------------------------------------ settlement keys

    /**
     * Claims a settlement key before any money moves.
     *
     * @param string   $key            `order:{id}` or `pair:{settlement id}`
     * @param string   $kind           issuer-buy | system-buy-back | pair
     * @param int      $orderId
     * @param int|null $matchedOrderId
     * @param string   $token
     *
     * @return string CLAIMED (go ahead), ALREADY_COMPLETED (money moved before: finish, don't pay),
     *                BLOCKED (claimed or in review: don't touch) or ERROR (can't tell: don't move money)
     */
    public function beginSettlement($key, $kind, $orderId, $matchedOrderId, $token)
    {
        $now = $this->now();
        try {
            $this->db->executeStatement(
                'INSERT INTO `' . self::KEYS_TABLE . '` (`settlement_key`, `kind`, `order_id`, `matched_order_id`, `status`, `claim_token`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array((string)$key, (string)$kind, (int)$orderId, $matchedOrderId === null ? null : (int)$matchedOrderId, self::KEY_CLAIMED, $token, $now, $now)
            );

            return self::CLAIMED;
        } catch (UniqueConstraintViolationException $e) {
            // there is a key already: see below
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Settlement %s: the settlement key could not be written, nothing moved: %s', $key, $e->getMessage()), array(__CLASS__));

            return self::ERROR;
        }

        // A failed attempt (nothing moved) may be claimed again, by exactly one run.
        if ($this->exactlyOne(
            'UPDATE `' . self::KEYS_TABLE . '` SET `status` = ?, `claim_token` = ?, `updated_at` = ? WHERE `settlement_key` = ? AND `status` = ?',
            array(self::KEY_CLAIMED, $token, $now, (string)$key, self::KEY_FAILED),
            "re-claim settlement {$key}"
        )) {
            return self::CLAIMED;
        }

        $status = $this->settlementStatus($key);
        if ($status === self::KEY_COMPLETED) {
            return self::ALREADY_COMPLETED;
        }

        return $status === null ? self::ERROR : self::BLOCKED;
    }

    /**
     * @param string $key
     *
     * @return string|null
     */
    public function settlementStatus($key)
    {
        try {
            $status = $this->db->fetchOne('SELECT `status` FROM `' . self::KEYS_TABLE . '` WHERE `settlement_key` = ?', array((string)$key));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Settlement %s: the key could not be read: %s', $key, $e->getMessage()), array(__CLASS__));

            return null;
        }

        return $status === false ? null : (string)$status;
    }

    /**
     * Notes which legs have moved so far, for whoever reviews a crash.
     */
    public function recordProgress($key, $token, array $progress)
    {
        return $this->exactlyOne(
            'UPDATE `' . self::KEYS_TABLE . '` SET `progress` = ?, `updated_at` = ? WHERE `settlement_key` = ? AND `status` = ? AND `claim_token` = ?',
            array(json_encode($progress), $this->now(), (string)$key, self::KEY_CLAIMED, $token),
            "record progress of settlement {$key}"
        );
    }

    /** Every leg moved. */
    public function completeSettlement($key, $token, $note = '')
    {
        return $this->setKeyStatus($key, $token, self::KEY_COMPLETED, $note);
    }

    /** Nothing moved (or all of it was put back): may be claimed again. */
    public function failSettlement($key, $token, $note)
    {
        return $this->setKeyStatus($key, $token, self::KEY_FAILED, $note);
    }

    /** Needs a human. */
    public function reviewSettlement($key, $token, $note)
    {
        $done = $this->setKeyStatus($key, $token, self::KEY_REVIEW, $note);
        $this->critical("settlement {$key} flagged for review: {$note}", array('settlement_key' => (string)$key));

        return $done;
    }

    // ------------------------------------------------------------------ crash recovery

    /**
     * Run with the run lock held, before anything else. Orders left `processing` by a crashed run are:
     *   - finished when their settlement key is completed (the money moved; only the status was missing);
     *   - released to pending when their key is failed or missing (nothing moved);
     *   - flagged for review otherwise (money may have moved): never retried.
     *
     * @param int $staleAfterSeconds
     *
     * @return int[] order ids flagged for review
     */
    public function recoverStaleClaims($staleAfterSeconds = self::STALE_AFTER_SECONDS)
    {
        $cutoff = date('Y-m-d H:i:s', time() - (int)$staleAfterSeconds);
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT `id`, `claim_token` FROM `' . self::ORDERS_TABLE . '` WHERE `status` = ? AND (`claimed_at` IS NULL OR `claimed_at` <= ?)',
                array(Orders::STATUS_PROCESSING, $cutoff)
            );
        } catch (\Throwable $e) {
            $this->logger->error('Stale settlement claims could not be read: ' . $e->getMessage(), array(__CLASS__));

            return array();
        }

        $flagged = array();
        foreach ($rows as $row) {
            $orderId = (int)$row['id'];
            $token = (string)$row['claim_token'];
            $key = self::orderKey($orderId);
            $status = $this->settlementStatus($key);
            if ($status === self::KEY_COMPLETED) {
                $this->finishOrder($orderId, $token, 'Completed after an interrupted match engine run');
                $this->logger->warning("Order {$orderId}: settled before a crash; marked processed", array(__CLASS__));
            } elseif ($status === self::KEY_FAILED || ($status === null && $this->keyIsAbsent($key))) {
                $this->releaseOrder($orderId, $token);
                $this->logger->warning("Order {$orderId}: claimed by a crashed run before any money moved; back to pending", array(__CLASS__));
            } else {
                $this->setKeyStatus($key, null, self::KEY_REVIEW, 'claimed by a run that never finished');
                $this->flagOrder($orderId, null, "a match engine run stopped while settling it (settlement {$key}: " . ($status ?: 'unknown') . '); check transactions, venbalance and wallets');
                $flagged[] = $orderId;
            }
        }

        return $flagged;
    }

    /**
     * @param int $orderId
     *
     * @return string
     */
    public static function orderKey($orderId)
    {
        return 'order:' . (int)$orderId;
    }

    /**
     * @param int $settlementId ultra_custom_buy_sell_order_settlements.id
     *
     * @return string
     */
    public static function pairKey($settlementId)
    {
        return 'pair:' . (int)$settlementId;
    }

    // ------------------------------------------------------------------ helpers

    private function keyIsAbsent($key)
    {
        try {
            return (int)$this->db->fetchOne('SELECT COUNT(*) FROM `' . self::KEYS_TABLE . '` WHERE `settlement_key` = ?', array((string)$key)) === 0;
        } catch (\Throwable $e) {
            return false; // can't tell: not absent
        }
    }

    private function setKeyStatus($key, $token, $status, $note)
    {
        $sql = 'UPDATE `' . self::KEYS_TABLE . '` SET `status` = ?, `note` = ?, `updated_at` = ? WHERE `settlement_key` = ? AND `status` = ?';
        $params = array($status, substr((string)$note, 0, 1000), $this->now(), (string)$key, self::KEY_CLAIMED);
        if ($token !== null) {
            $sql .= ' AND `claim_token` = ?';
            $params[] = $token;
        }

        return $this->exactlyOne($sql, $params, "mark settlement {$key} {$status}");
    }

    private function exactlyOne($sql, array $params, $what)
    {
        try {
            $rows = (int)$this->db->executeStatement($sql, $params);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Could not %s: %s', $what, $e->getMessage()), array(__CLASS__));

            return false;
        }

        return $rows === 1;
    }

    private function critical($message, array $context)
    {
        $line = '[ultra-core match-engine] CRITICAL ' . $message;
        $this->logger->critical($line, $context + array(__CLASS__));
        error_log($line);
    }

    private function now()
    {
        return date('Y-m-d H:i:s');
    }
}
