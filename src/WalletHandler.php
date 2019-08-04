<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 01-07-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Model\Eloquent\Wallet;
use Hub\UltraCore\Exception\InsufficientAssetAvailabilityException;
use Hub\UltraCore\Exception\InsufficientBalanceException;
use Hub\UltraCore\Exception\InsufficientVenBalanceException;

interface WalletHandler
{
    /**
     * Purchases a requested amount from the given asset and credits it into the given user's asset wallet.
     *
     * @param int $userId
     * @param UltraAsset $asset
     * @param float $purchaseAssetAmount
     * @throws InsufficientVenBalanceException
     * @throws InsufficientAssetAvailabilityException
     */
    public function purchase($userId, UltraAsset $asset, $purchaseAssetAmount);

    /**
     * @param int $userId
     * @param UltraAsset $asset
     * @param float $sellAssetAmount
     */
    public function sell($userId, UltraAsset $asset, $sellAssetAmount);

    /**
     * Transfer some asset amount to a friend. This will debit the sender's wallet with the equivalent amount.
     *
     * @param Wallet $senderWallet
     * @param int $receiverId
     * @param float $transferAssetAmount
     * @param string $message [optional]
     * @throws InsufficientBalanceException
     */
    public function gift(Wallet $senderWallet, $receiverId, $transferAssetAmount, $message = '');
}
