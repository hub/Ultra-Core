<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Ven;

use InvalidArgumentException;
use mysqli;
use RuntimeException;

/**
 * This manages the Hub Culture main wallet.
 *
 * @package Hub\UltraCore\Ven
 */
class UltraVenRepository
{
    /**
     * @var mysqli A main HubCulture database write connection
     */
    private $dbConnection;

    /**
     * UltraVenRepository Constructor
     *
     * @param mysqli $dbConnection A main HubCulture database write connection
     */
    public function __construct(mysqli $dbConnection)
    {
        $this->dbConnection = $dbConnection;
    }

    /**
     * @param int $userId Hub Culture user identifier.
     *
     * @return VenWallet
     */
    public function getVenWalletOfUser($userId)
    {
        $stmt = $this->dbConnection->prepare('SELECT `user`, `balance` FROM `venbalance` WHERE `user` = ?');
        $stmt->bind_param("i", $userId);
        $executed = $stmt->execute();
        if (!$executed) {
            return null;
        }

        $stmt->bind_result($user, $balance);
        while ($stmt->fetch()) {
            return new VenWallet($user, $balance);
        }

        return null;
    }

    /**
     * @param int    $fromUserId
     * @param int    $toUserId
     * @param string $amount
     * @param string $message
     * @param bool   $hide
     * @param string $special
     * @param string $type
     */
    public function sendVen($fromUserId, $toUserId, $amount, $message = '', $hide = false, $special = '', $type = '')
    {
        // A negative amount pulls Ven from the receiver (OAI 941479961, Jan 2026): only positive amounts move.
        $amount = round((float)$amount, 4);
        if (!is_finite($amount) || $amount <= 0) {
            throw new InvalidArgumentException(sprintf('A Ven amount must be greater than zero(0), got %s', var_export($amount, true)));
        }
        $fromUserId = (int)$fromUserId;
        $toUserId = (int)$toUserId;
        if ($fromUserId === $toUserId) {
            throw new InvalidArgumentException('Ven cannot be sent to the same account.');
        }
        if (is_null($this->getVenWalletOfUser($toUserId))) {
            throw new RuntimeException("Cannot find the Ven account of the receiver {$toUserId}.");
        }

        // venbalance is MyISAM: no transaction to roll back, but one UPDATE is atomic. Take the Ven only if it is
        // there, write the receipt, then credit; undo the debit if the receipt can't be written.
        if (!$this->debitVenWallet($fromUserId, $amount)) {
            $balance = $this->getVenWalletOfUser($fromUserId);
            throw new RuntimeException(sprintf(
                "Insufficient VEN balance of %s. Please topup to continue.",
                $balance ? $balance->getBalance() : 0
            ));
        }

        $preparedStmt = $this->dbConnection->prepare(<<<SQL
INSERT INTO `transactions` (`from`, `to`, `amount`, `timestamp`, `message`, `hide`, `special`, `type`)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
SQL
        );

        $now = time();
        $hide = intval($hide);
        $created = $preparedStmt && $preparedStmt->bind_param('iidisiss', $fromUserId, $toUserId, $amount, $now, $message, $hide, $special, $type)
            && $preparedStmt->execute();
        if (!$created) {
            $this->creditVenWallet($fromUserId, $amount);
            throw new RuntimeException('Cannot record the Ven transaction: ' . $this->dbConnection->error);
        }

        $this->creditVenWallet($toUserId, $amount);
    }

    private function creditVenWallet($userId, $amount)
    {
        $preparedStmt = $this->dbConnection->prepare('UPDATE `venbalance` SET `balance` = `balance` + ? WHERE `user` = ?');
        $preparedStmt->bind_param('di', $amount, $userId);
        return $preparedStmt->execute();
    }

    /**
     * @param int   $userId Hub Culture user identifier.
     * @param float $amount
     *
     * @return bool
     */
    /**
     * @return bool true only when the account had the Ven and it was taken
     */
    private function debitVenWallet($userId, $amount)
    {
        $preparedStmt = $this->dbConnection->prepare('UPDATE `venbalance` SET `balance` = `balance` - ? WHERE `user` = ? AND `balance` >= ?');
        $preparedStmt->bind_param('did', $amount, $userId, $amount);
        return $preparedStmt->execute() && $preparedStmt->affected_rows === 1;
    }
}
