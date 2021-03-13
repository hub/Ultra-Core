<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Wallet;

use Hub\UltraCore\Exception\WalletException;
use Hub\UltraCore\MatchEngine\MatchedOrderMetaData;
use Hub\UltraCore\MatchEngine\Order\Orders;
use mysqli;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class WalletRepository
{
    /**
     * @var mysqli
     */
    private $dbConnection;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * WalletRepository constructor.
     *
     * @param mysqli               $dbConnection A main HubCulture database write connection
     * @param LoggerInterface|null $logger
     */
    public function __construct(mysqli $dbConnection, LoggerInterface $logger = null)
    {
        $this->dbConnection = $dbConnection;
        $this->logger = is_null($logger) ? new NullLogger() : $logger;
    }

    /**
     * @param int $userId Hub Culture user identifier.
     * @param int $assetId
     *
     * @return Wallet|null
     */
    public function getUserWallet($userId, $assetId)
    {
        $wallet = $this->getUserWalletOrNull($userId, $assetId);
        if (empty($wallet)) {
            $preparedStmt = $this->dbConnection->prepare(<<<SQL
INSERT INTO `wallets` (`user_id`, `asset_id`, `balance`, `public_key`)
VALUES (?, ?, ?, ?)
SQL
            );

            $publicKey = md5(sprintf('%d:%d:%s', $userId, $assetId, time()));
            $balance = 0;
            $preparedStmt->bind_param('iiis', $userId, $assetId, $balance, $publicKey);
            $executed = $preparedStmt->execute();
            if (!$executed) {
                $preparedStmt->close();
                return null;
            }

            $wallet = new Wallet($preparedStmt->insert_id, $userId, $assetId, $balance, $balance, $publicKey);
            $this->logger->debug("A new wallet has been created for user [{$userId}] for asset [{$assetId}]");
            $preparedStmt->close();
        }

        return $wallet;
    }

    /**
     * This return a user ultra wallet by a public wallet identifier.
     *
     * @param string $walletPublicKey A valid public key of an ultra asset wallet
     *
     * @return Wallet|null
     */
    public function getUserWalletByPublicKey($walletPublicKey)
    {
        $stmt = $this->dbConnection->prepare(
            'SELECT `id`, `user_id`, `asset_id`, `balance`, `available_balance` FROM `wallets` WHERE `public_key` = ?'
        );
        $stmt->bind_param("s", $walletPublicKey);
        $executed = $stmt->execute();
        if (!$executed) {
            $stmt->close();
            return null;
        }

        $stmt->bind_result($id, $userId, $assetId, $balance, $availableBalance);
        if ($stmt->fetch()) {
            $stmt->close();
            return new Wallet($id, $userId, $assetId, $balance, $availableBalance, $walletPublicKey);
        }

        return null;
    }

    /**
     * Use this to get all the wallets belong to a given user.
     *
     * @param int $userId A valid user id.
     *
     * @return Wallet[]
     */
    public function getUserWallets($userId)
    {
        $stmt = $this->dbConnection->prepare(
            'SELECT `id`, `user_id`, `asset_id`, `balance`, `available_balance`, `public_key` FROM `wallets` WHERE `user_id` = ?'
        );
        $stmt->bind_param("i", $userId);
        $executed = $stmt->execute();
        if (!$executed) {
            $stmt->close();
            return array();
        }

        $wallets = array();
        $stmt->bind_result($id, $userId, $assetId, $balance, $availableBalance, $publicKey);
        while ($stmt->fetch()) {
            $wallets[] = new Wallet($id, $userId, $assetId, $balance, $availableBalance, $publicKey);
        }
        $stmt->close();

        return $wallets;
    }

    /**
     * @param int $userId Hub Culture user identifier.
     * @param int $assetId
     *
     * @return Wallet|null
     */
    public function getUserWalletOrNull($userId, $assetId)
    {
        $stmt = $this->dbConnection->prepare(
            'SELECT `id`, `user_id`, `asset_id`, `balance`, `available_balance`, `public_key` FROM `wallets` WHERE `user_id` = ? AND `asset_id` = ?'
        );
        $stmt->bind_param("ii", $userId, $assetId);
        $executed = $stmt->execute();
        if (!$executed) {
            $stmt->close();
            return null;
        }

        $stmt->bind_result($id, $userId, $assetId, $balance, $availableBalance, $publicKey);
        if ($stmt->fetch()) {
            $stmt->close();
            return new Wallet($id, $userId, $assetId, $balance, $availableBalance, $publicKey);
        }

        return null;
    }

    /**
     * Use this to retrieve all the tranasactions for all wallets of a given user.
     *
     * @param int $userId Hub Culture user identifier.
     * @param int $offset
     * @param int $limit
     *
     * @return array|null
     */
    public function getTransactionsByUserId($userId, $offset = 0, $limit = 10)
    {
        if (intval($userId) === 0) {
            return array();
        }

        $offset = intval($offset) === 0 ? 0 : intval($offset);
        $limit = intval($limit) === 0 ? 10 : intval($limit);

        $transactions = array();
        $resultSet = $this->dbConnection->query(<<<SQL
            SELECT
                a.id AS `asset_id`,
                a.title AS `asset_title`,
                a.ticker_symbol AS `asset_ticker_symbol`,
                a.num_assets AS `asset_balance`,
                w.balance AS `wallet_balance`,
                w.available_balance AS `wallet_available_balance`,
                t.*
            FROM `wallet_transactions` t
            INNER JOIN `wallets` w ON (w.id = t.wallet_id)
            INNER JOIN `ultra_assets` a ON (a.id = w.asset_id)
            WHERE
                t.`user_id` = {$userId}
            ORDER BY t.`created_at` DESC
            LIMIT {$offset}, {$limit}
SQL
        );
        while ($transaction = $resultSet->fetch_assoc()) {
            unset($transaction['id']);
            unset($transaction['user_id']);
            unset($transaction['committed_by']);
            unset($transaction['balance']); // no need to display the current balance of the wallet
            unset($transaction['is_transfer']);
            unset($transaction['transfer_message']);
            unset($transaction['transfer_related_user']);
            unset($transaction['updated_at']);
            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * Use this to retrieve all the transactions for all wallets of a given user.
     *
     * @param int $userId   Hub Culture user identifier.
     * @param int $walletId A valid wallet id belongs to a user.
     * @param int $offset
     * @param int $limit
     *
     * @return array|null
     */
    public function getWalletTransactionsByUserId($userId, $walletId, $offset = 0, $limit = 10)
    {
        if (intval($userId) === 0 || intval($walletId) === 0) {
            return array();
        }

        $offset = intval($offset) === 0 ? 0 : intval($offset);
        $limit = intval($limit) === 0 ? 10 : intval($limit);

        $transactions = array();
        $resultSet = $this->dbConnection->query(<<<SQL
            SELECT
                a.id AS `asset_id`,
                a.title AS `asset_title`,
                a.ticker_symbol AS `asset_ticker_symbol`,
                a.num_assets AS `asset_balance`,
                w.balance AS `wallet_balance`,
                w.available_balance AS `wallet_available_balance`,
                t.*
            FROM `wallet_transactions` t
            INNER JOIN `wallets` w ON (w.id = t.wallet_id)
            INNER JOIN `ultra_assets` a ON (a.id = w.asset_id)
            WHERE
                t.`user_id` = {$userId}
                AND t.`wallet_id` = {$walletId}
            ORDER BY t.`created_at` DESC
            LIMIT {$offset}, {$limit}
SQL
        );
        while ($transaction = $resultSet->fetch_assoc()) {
            unset($transaction['id']);
            unset($transaction['user_id']);
            unset($transaction['committed_by']);
            unset($transaction['balance']); // no need to display the current balance of the wallet
            unset($transaction['is_transfer']);
            unset($transaction['transfer_message']);
            unset($transaction['transfer_related_user']);
            unset($transaction['updated_at']);
            $transactions[] = $transaction;
        }

        return $transactions;
    }

    /**
     * @param Wallet $wallet user wallet value object
     * @param float  $amount amount to be credited
     * @param array  $metaData
     *
     * @throws WalletException
     */
    public function credit(Wallet $wallet, $amount, array $metaData)
    {
        $this->logger->debug(sprintf('Crediting / Adding [%s] to the wallet %d', $amount, $wallet->getId()));
        $balance = $wallet->getBalance();
        if (!$this->isPendingTransaction($metaData)) {
            $balance = $wallet->getBalance() + $amount;
        }

        $availableBalance = $wallet->getAvailableBalance() + $amount;
        $userId = $wallet->getUserId();
        $assetId = $wallet->getAssetId();
        $this->dbConnection->begin_transaction();
        $preparedStmt = $this->dbConnection->prepare('UPDATE `wallets` SET `balance` = ?, `available_balance` = ? WHERE `user_id` = ? AND `asset_id` = ?');
        if (!$preparedStmt) {
            $this->logger->error("Cannot update the wallet balance. Error : " . $this->dbConnection->error);
            throw new WalletException("Cannot update the wallet balance");
        }
        $preparedStmt->bind_param('ddii', $balance, $availableBalance, $userId, $assetId);
        $updated = $preparedStmt->execute();
        $preparedStmt->close();
        $transactionLogged = false;
        if ($updated) {
            $transactionLogged = $this->logTransaction($wallet, $amount, $balance, $metaData);
        }

        if (!$transactionLogged) {
            $this->dbConnection->rollback();
            $this->logger->error("Cannot log the wallet transaction. Error : " . $this->dbConnection->error);
            throw new WalletException("Cannot log the wallet transaction");
        }

        $this->dbConnection->commit();
    }

    /**
     * @param Wallet $wallet
     * @param float  $amount
     * @param array  $metaData
     */
    public function debit(Wallet $wallet, $amount, array $metaData)
    {
        $this->logger->debug(sprintf('Debiting / Deducting [%s] from the wallet %d', $amount, $wallet->getId()));
        $balance = $wallet->getBalance();
        if (!$this->isPendingTransaction($metaData)) {
            $balance = $wallet->getBalance() - $amount;
        }

        $availableBalance = $wallet->getAvailableBalance() - $amount;
        $userId = $wallet->getUserId();
        $assetId = $wallet->getAssetId();
        $this->dbConnection->begin_transaction();
        $preparedStmt = $this->dbConnection->prepare('UPDATE `wallets` SET `balance` = ?, `available_balance` = ? WHERE `user_id` = ? AND `asset_id` = ?');
        if (!$preparedStmt) {
            $this->logger->error("Cannot update the wallet balance. Error : " . $this->dbConnection->error);
            throw new WalletException("Cannot update the wallet balance");
        }
        $preparedStmt->bind_param('ddii', $balance, $availableBalance, $userId, $assetId);
        $updated = $preparedStmt->execute();
        $preparedStmt->close();
        $transactionLogged = false;
        if ($updated) {
            $transactionLogged = $this->logTransaction($wallet, $amount * -1, $balance, $metaData);
        }

        if (!$transactionLogged) {
            $this->dbConnection->rollback();
            $this->logger->error("Cannot log the wallet transaction. Error : " . $this->dbConnection->error);
            throw new WalletException("Cannot log the wallet transaction");
        }

        $this->dbConnection->commit();
    }

    /**
     * @param Wallet $wallet
     * @param float  $amount
     * @param float  $balance
     * @param array  $metaData
     *
     * @return bool
     */
    private function logTransaction(Wallet $wallet, $amount, $balance, array $metaData)
    {
        $userId = $wallet->getUserId();
        $walletId = $wallet->getId();
        $assetAmount = $amount;
        $isCommitted = $this->isPendingTransaction($metaData) ? 0 : 1;
        $commitOutcome = $this->isPendingTransaction($metaData) ? Orders::STATUS_PENDING : Orders::STATUS_PROCESSED;
        $assetAmount_in_ven = !empty($metaData[MatchedOrderMetaData::ASSET_AMOUNT_IN_VEN]) ? $metaData[MatchedOrderMetaData::ASSET_AMOUNT_IN_VEN] : 0;
        $assetAmount_for_one_ven = !empty($metaData['asset_amount_for_one_ven']) ? $metaData['asset_amount_for_one_ven'] : 0;
        $venAmountForOneAsset = !empty($metaData[MatchedOrderMetaData::VEN_AMOUNT_FOR_ONE_ASSET]) ? $metaData[MatchedOrderMetaData::VEN_AMOUNT_FOR_ONE_ASSET] : 0;
        $assetWeightingConfig = !empty($metaData['weightingConfig']) ? json_encode($metaData['weightingConfig']) : '[]';
        $isTransfer = isset($metaData['is_transfer']) ? 1 : 0;
        $transferMessage = !empty($metaData['transfer_message']) ? $metaData['transfer_message'] : '';
        $transferRelatedUser = !empty($metaData['transfer_related_user']) ? $metaData['transfer_related_user'] : '';
        $assetAmountForWithdrawalFee = !empty($metaData['asset_amount_for_withdrawal_fee']) ? $metaData['asset_amount_for_withdrawal_fee'] : '';
        $assetAmountForExchangeCommission = !empty($metaData['asset_amount_for_exchange_fee']) ? $metaData['asset_amount_for_exchange_fee'] : '';
        $metaDataEncoded = json_encode($metaData);

        $preparedStmt = $this->dbConnection->prepare(<<<SQL
INSERT INTO `wallet_transactions` (
    `user_id`, `wallet_id`, `balance`, `asset_amount`, `is_committed`, `asset_amount_in_ven`, `asset_amount_for_one_ven`,
    `ven_amount_for_one_asset`, `asset_weighting_config`, `is_transfer`, `transfer_message`, `transfer_related_user`,
    `asset_amount_for_withdrawal_fee`, `asset_amount_for_exchange_fee`, `meta_data`, `commit_outcome`,
    `updated_at`, `created_at`
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
SQL
        );
        $preparedStmt->bind_param('iiddidddsissssss',
            $userId,
            $walletId,
            $balance,
            $assetAmount,
            $isCommitted,
            $assetAmount_in_ven,
            $assetAmount_for_one_ven,
            $venAmountForOneAsset,
            $assetWeightingConfig,
            $isTransfer,
            $transferMessage,
            $transferRelatedUser,
            $assetAmountForWithdrawalFee,
            $assetAmountForExchangeCommission,
            $metaDataEncoded,
            $commitOutcome
        );

        $executed = $preparedStmt->execute();
        $preparedStmt->close();
        return $executed;
    }

    /**
     * @param array $metaData
     *
     * @return bool
     */
    private function isPendingTransaction(array $metaData)
    {
        return (isset($metaData['commit']) && $metaData['commit'] === false);
    }
}
