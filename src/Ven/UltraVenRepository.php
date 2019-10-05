<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Ven;

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
        $balance = $this->getVenWalletOfUser($fromUserId);
        if (($balance->getBalance() - $amount) < 0) {
            throw new RuntimeException("Insufficient VEN balance of {$balance->getBalance()}. Please topup to continue.");
        }

        $preparedStmt = $this->dbConnection->prepare(<<<SQL
INSERT INTO `transactions` (`from`, `to`, `amount`, `timestamp`, `message`, `hide`, `special`, `type`)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)
SQL
        );

        $now = time();
        $hide = intval($hide);
        $preparedStmt->bind_param('iidisiss', $fromUserId, $toUserId, $amount, $now, $message, $hide, $special, $type);
        $created = $preparedStmt->execute();
        if ($created) {
            // deduct/debit ven from the sender
            $this->debitVenWallet($fromUserId, $amount);

            // add/credit ven to the receiver
            $this->creditVenWallet($toUserId, $amount);
        }
    }

    /**
     * @param int   $userId Hub Culture user identifier.
     * @param float $amount
     *
     * @return bool
     */
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
    private function debitVenWallet($userId, $amount)
    {
        $preparedStmt = $this->dbConnection->prepare('UPDATE `venbalance` SET `balance` = `balance` - ? WHERE `user` = ?');
        $preparedStmt->bind_param('di', $amount, $userId);
        return $preparedStmt->execute();
    }
}
