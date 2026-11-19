<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Ven;

use Hub\UltraCore\Exception\VenLedgerInconsistentException;
use Hub\UltraCore\Exception\VenLegFailedException;
use Hub\UltraCore\Exception\VenTransferException;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Moves Ven between `venbalance` rows, safely on MyISAM as well as InnoDB. Same pattern as hub/Hub's
 * app/Ven/VenLedger.php (hub/Hub#884); Ultra-Core can't depend on Hub, so it is replicated here.
 *
 * Every balance change is one statement with the arithmetic inside the UPDATE, and every one must change exactly
 * one row:
 *
 *   debit:  UPDATE venbalance SET balance = ROUND(balance - a, 2) WHERE user = u AND balance >= a LIMIT 1
 *   credit: UPDATE venbalance SET balance = ROUND(balance + a, 2) WHERE user = u LIMIT 1
 *
 * A transfer is debit, receipt (`transactions` row), credit. When both tables are InnoDB it runs inside a database
 * transaction and any failure rolls back. Otherwise (MyISAM) a failure after the debit credits the sender back and
 * removes the receipt. If even that fails, or a commit/rollback outcome is unknown, it logs CRITICAL with a record
 * a human can repair from, and throws VenLedgerInconsistentException. Ven is never silently destroyed or doubled.
 *
 * Amount guards (hub/Hub incident action 7): finite, above zero, at most two decimals, no self-transfer.
 */
class VenLedger
{
    const LOG_PREFIX = '[ultra-core ven-ledger]';

    /** @var LedgerConnection */
    private $db;

    /** @var LoggerInterface */
    private $logger;

    /** @var string|null */
    private $lastError;

    /**
     * @param LedgerConnection     $db
     * @param LoggerInterface|null $logger
     */
    public function __construct(LedgerConnection $db, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->logger = $logger ?: new NullLogger();
    }

    // ------------------------------------------------------------------ guards

    /**
     * Why an amount can't be moved, or null when it can.
     *
     * @param mixed $amount
     *
     * @return string|null
     */
    public static function amountError($amount)
    {
        if ($amount === null || is_bool($amount) || is_array($amount) || is_object($amount) || !is_numeric($amount)) {
            return 'The amount must be a number.';
        }
        $value = (float)$amount;
        if (!is_finite($value)) {
            return 'The amount must be a finite number.';
        }
        if ($value <= 0) {
            return 'The amount must be more than zero.';
        }
        if (!self::hasAtMostTwoDecimals($amount, $value)) {
            return 'The amount can have at most two decimals.';
        }

        return null;
    }

    /**
     * @param mixed $amount
     *
     * @return float the amount, in cents precision
     * @throws VenTransferException
     */
    public static function normalise($amount)
    {
        $error = self::amountError($amount);
        if ($error !== null) {
            throw new VenTransferException(
                sprintf('%s Got %s.', $error, is_scalar($amount) ? var_export($amount, true) : gettype($amount)),
                VenTransferException::INVALID_AMOUNT
            );
        }

        return round((float)$amount, 2);
    }

    /**
     * Rounds a computed Ven amount (price × units) to cents, the precision the ledger keeps.
     *
     * @param float $amount
     *
     * @return float
     */
    public static function toCents($amount)
    {
        return round((float)$amount, 2);
    }

    /**
     * @param mixed $raw
     * @param float $value
     *
     * @return bool
     */
    private static function hasAtMostTwoDecimals($raw, $value)
    {
        if (is_string($raw) && preg_match('/^\s*\+?(\d*)(?:\.(\d*))?\s*$/', $raw, $m)) {
            return strlen(rtrim(isset($m[2]) ? $m[2] : '', '0')) <= 2;
        }
        $cents = $value * 100;
        // Float noise such as 0.1 + 0.2 is fine; a real third decimal is not.
        $tolerance = max(1e-6, abs($cents) * 1e-15);

        return abs($cents - round($cents)) <= $tolerance;
    }

    // ------------------------------------------------------------------ reads

    /**
     * @param int $userId
     *
     * @return bool|null null when the ledger can't be read
     */
    public function hasAccount($userId)
    {
        $userId = (int)$userId;
        if ($userId <= 0) {
            return false;
        }
        $count = $this->db->fetchValue('SELECT COUNT(*) FROM `venbalance` WHERE `user` = ?', array($userId));

        return $count === null ? null : ((int)$count > 0);
    }

    /**
     * @param int $userId
     *
     * @return string|null
     */
    public function balance($userId)
    {
        $value = $this->db->fetchValue('SELECT `balance` FROM `venbalance` WHERE `user` = ? LIMIT 1', array((int)$userId));

        return $value === null ? null : (string)$value;
    }

    // ------------------------------------------------------------------ writes

    /**
     * Takes Ven only if the balance covers it, in one statement.
     *
     * @param int   $userId
     * @param float $amount already normalised
     *
     * @return bool true when exactly one balance was debited
     */
    public function debit($userId, $amount)
    {
        $this->lastError = null;
        $n = $this->db->execute(
            'UPDATE `venbalance` SET `balance` = ROUND(`balance` - ?, 2) WHERE `user` = ? AND `balance` >= ? LIMIT 1',
            array((float)$amount, (int)$userId, (float)$amount)
        );
        if ($n === false) {
            $this->lastError = $this->db->lastError() ?: 'database error';
        }

        return $n === 1;
    }

    /**
     * Adds Ven to a balance, in one statement.
     *
     * @param int   $userId
     * @param float $amount already normalised
     *
     * @return bool true when exactly one balance was credited
     */
    public function credit($userId, $amount)
    {
        $this->lastError = null;
        $n = $this->db->execute(
            'UPDATE `venbalance` SET `balance` = ROUND(`balance` + ?, 2) WHERE `user` = ? LIMIT 1',
            array((float)$amount, (int)$userId)
        );
        if ($n === false) {
            $this->lastError = $this->db->lastError() ?: 'database error';
        }

        return $n === 1;
    }

    /**
     * Moves Ven from one member to another and records it in `transactions`.
     *
     * @param int    $fromUserId
     * @param int    $toUserId
     * @param mixed  $amount
     * @param string $message
     * @param bool   $hide
     * @param string $special
     * @param string $type
     *
     * @return int the `transactions` id
     * @throws VenTransferException          refused; nothing moved
     * @throws VenLegFailedException         a leg failed after the debit and it was reversed; nothing moved
     * @throws VenLedgerInconsistentException the ledger may be off; logged CRITICAL. Don't retry
     */
    public function transfer($fromUserId, $toUserId, $amount, $message = '', $hide = false, $special = '', $type = '')
    {
        $amount = self::normalise($amount);
        if (!is_numeric($fromUserId) || !is_numeric($toUserId) || (int)$fromUserId <= 0 || (int)$toUserId <= 0) {
            throw new VenTransferException('Both Ven accounts must be valid members.', VenTransferException::NO_ACCOUNT);
        }
        $from = (int)$fromUserId;
        $to = (int)$toUserId;
        if ($from === $to) {
            throw new VenTransferException('Ven cannot be sent to the same account.', VenTransferException::SELF_TRANSFER);
        }
        $record = array('from' => $from, 'to' => $to, 'amount' => sprintf('%.2F', $amount), 'message' => (string)$message);

        foreach (array($to => 'receiver', $from => 'sender') as $userId => $role) {
            $exists = $this->hasAccount($userId);
            if ($exists === null) {
                throw new VenTransferException('The Ven ledger could not be read. Nothing was moved.', VenTransferException::LEDGER_ERROR, $record);
            }
            if (!$exists) {
                throw new VenTransferException("Cannot find the Ven account of the {$role} {$userId}.", VenTransferException::NO_ACCOUNT, $record);
            }
        }

        $inTransaction = $this->db->beginIfTransactional();
        $record['mode'] = $inTransaction ? 'transaction' : 'conditional-writes';

        // 1. debit, only if the Ven is there
        $debited = false;
        try {
            $debited = $this->debit($from, $amount);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
        }
        if (!$debited) {
            if ($inTransaction && !$this->db->rollBack()) {
                // nothing was written, but say so loudly: the connection is in an unknown state
                $this->logger->error(self::LOG_PREFIX . ' rollback after a refused debit failed: ' . $this->db->lastError(), $record);
            }
            if ($this->lastError !== null) {
                throw new VenTransferException('The Ven ledger could not be updated. Nothing was moved.', VenTransferException::LEDGER_ERROR, $record);
            }
            $balance = $this->balance($from);
            throw new VenTransferException(
                sprintf('Insufficient VEN balance of %s. Please topup to continue.', $balance === null ? 0 : $balance),
                VenTransferException::INSUFFICIENT_FUNDS,
                $record
            );
        }

        // 2. receipt
        $transactionId = false;
        try {
            $transactionId = $this->db->insert(
                'INSERT INTO `transactions` (`from`, `to`, `amount`, `timestamp`, `message`, `hide`, `special`, `type`) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                array($from, $to, $amount, time(), (string)$message, $hide ? 1 : 0, (string)$special, (string)$type)
            );
        } catch (\Throwable $e) {
            $transactionId = false;
        }
        if (!$transactionId) {
            $this->undo($inTransaction, $from, $amount, null, VenTransferException::RECEIPT_FAILED, 'the transaction row could not be written', $record);
        }
        $record['transaction_id'] = (int)$transactionId;

        // 3. credit; exactly one row
        $credited = false;
        try {
            $credited = $this->credit($to, $amount);
        } catch (\Throwable $e) {
            $credited = false;
        }
        if (!$credited) {
            $this->undo($inTransaction, $from, $amount, (int)$transactionId, VenTransferException::CREDIT_FAILED, "the receiver {$to} could not be credited", $record);
        }

        if ($inTransaction && !$this->db->commit()) {
            // The server may or may not have applied it: never guess.
            $this->critical('commit outcome unknown after debit, receipt and credit', $record + array('db_error' => $this->db->lastError()));
            throw new VenLedgerInconsistentException(
                'The Ven transfer could not be confirmed. It has been flagged for review; do not retry it.',
                VenTransferException::INCONSISTENT,
                $record
            );
        }

        return (int)$transactionId;
    }

    /**
     * Puts the debit back after a later leg failed, then throws.
     *
     * @param bool     $inTransaction
     * @param int      $from
     * @param float    $amount
     * @param int|null $transactionId the receipt to remove, when one was written
     * @param string   $reason
     * @param string   $why
     * @param array    $record
     *
     * @throws VenLegFailedException|VenLedgerInconsistentException always
     */
    private function undo($inTransaction, $from, $amount, $transactionId, $reason, $why, array $record)
    {
        if ($inTransaction) {
            if ($this->db->rollBack()) {
                $this->logger->warning(self::LOG_PREFIX . " rolled back: {$why}", $record);
                throw new VenLegFailedException("The Ven transfer failed ({$why}); nothing was moved.", $reason, $record);
            }
            $this->critical("rollback FAILED after: {$why}", $record + array('db_error' => $this->db->lastError()));
            throw new VenLedgerInconsistentException(
                "The Ven transfer failed ({$why}) and could not be rolled back. It has been flagged for review.",
                VenTransferException::INCONSISTENT,
                $record
            );
        }

        // MyISAM: credit the sender back (one row, exactly), then remove the receipt
        $returned = false;
        try {
            $returned = $this->credit($from, $amount);
        } catch (\Throwable $e) {
            $returned = false;
        }
        if (!$returned) {
            $this->critical(
                "debited {$record['amount']} Ven from user {$from} but {$why}, and the debit could NOT be reversed: credit it back by hand",
                $record + array('state' => 'debited-not-credited', 'repair' => "credit {$record['amount']} Ven to user {$from}" . ($transactionId ? " and delete transactions.id {$transactionId}" : ''))
            );
            throw new VenLedgerInconsistentException(
                "The Ven transfer failed ({$why}) and the Ven could not be returned. It has been flagged for review.",
                VenTransferException::INCONSISTENT,
                $record
            );
        }

        if ($transactionId) {
            $deleted = false;
            try {
                $deleted = $this->db->execute('DELETE FROM `transactions` WHERE `id` = ? LIMIT 1', array((int)$transactionId)) === 1;
            } catch (\Throwable $e) {
                $deleted = false;
            }
            if (!$deleted) {
                $this->critical(
                    "balances restored after {$why}, but transactions.id {$transactionId} records a transfer that did not happen",
                    $record + array('state' => 'stray-receipt', 'repair' => "delete transactions.id {$transactionId}")
                );
                throw new VenLedgerInconsistentException(
                    "The Ven transfer failed ({$why}); the Ven was returned but its record could not be removed. It has been flagged for review.",
                    VenTransferException::INCONSISTENT,
                    $record
                );
            }
        }

        $this->logger->warning(self::LOG_PREFIX . " COMPENSATED: returned {$record['amount']} Ven to user {$from} after {$why}", $record);
        throw new VenLegFailedException("The Ven transfer failed ({$why}); the Ven was returned.", $reason, $record);
    }

    /**
     * CRITICAL in the PSR log and in the PHP error log, as one JSON line a human can repair from.
     *
     * @param string $what
     * @param array  $record
     */
    private function critical($what, array $record)
    {
        $line = self::LOG_PREFIX . ' CRITICAL ' . $what . ' ' . json_encode($record);
        $this->logger->critical($line, $record);
        error_log($line);
    }
}
