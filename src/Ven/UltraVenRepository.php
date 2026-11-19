<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Ven;

use Hub\UltraCore\Exception\VenLedgerInconsistentException;
use Hub\UltraCore\Exception\VenLegFailedException;
use Hub\UltraCore\Exception\VenTransferException;
use InvalidArgumentException;
use mysqli;
use Psr\Log\LoggerInterface;

/**
 * This manages the Hub Culture main wallet.
 *
 * @package Hub\UltraCore\Ven
 */
class UltraVenRepository
{
    /**
     * @var VenLedger
     */
    private $ledger;

    /**
     * UltraVenRepository Constructor
     *
     * @param mysqli|LedgerConnection $dbConnection A main HubCulture database write connection
     * @param LoggerInterface|null    $logger       receives the CRITICAL ledger records (they also go to error_log)
     */
    public function __construct($dbConnection, ?LoggerInterface $logger = null)
    {
        if ($dbConnection instanceof mysqli) {
            $dbConnection = new MysqliLedgerConnection($dbConnection);
        }
        if (!($dbConnection instanceof LedgerConnection)) {
            throw new InvalidArgumentException('UltraVenRepository needs a mysqli connection or a LedgerConnection.');
        }
        $this->ledger = new VenLedger($dbConnection, $logger);
    }

    /**
     * @param int $userId Hub Culture user identifier.
     *
     * @return VenWallet|null
     */
    public function getVenWalletOfUser($userId)
    {
        $balance = $this->ledger->balance($userId);

        return $balance === null ? null : new VenWallet((int)$userId, $balance);
    }

    /**
     * Moves Ven from one member to another: a conditional debit, the `transactions` receipt and a checked credit,
     * in a database transaction when the tables are InnoDB. See VenLedger.
     *
     * @param int    $fromUserId
     * @param int    $toUserId
     * @param string $amount  above zero, at most two decimals
     * @param string $message
     * @param bool   $hide
     * @param string $special
     * @param string $type
     *
     * @return int the `transactions` id
     * @throws VenTransferException           refused (amount, self-transfer, no account, insufficient funds); nothing moved
     * @throws VenLegFailedException          the receipt or the credit failed and the debit was reversed; nothing moved
     * @throws VenLedgerInconsistentException the reversal failed or the outcome is unknown; logged CRITICAL, never retry
     */
    public function sendVen($fromUserId, $toUserId, $amount, $message = '', $hide = false, $special = '', $type = '')
    {
        return $this->ledger->transfer($fromUserId, $toUserId, $amount, $message, $hide, $special, $type);
    }
}
