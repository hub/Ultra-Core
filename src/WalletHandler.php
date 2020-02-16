<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 01-07-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Exception\InsufficientAssetAvailabilityException;
use Hub\UltraCore\Exception\InsufficientBalanceException;
use Hub\UltraCore\Exception\InsufficientUltraAssetBalanceException;
use Hub\UltraCore\Exception\InsufficientVenBalanceException;
use Hub\UltraCore\Exception\WalletException;
use Hub\UltraCore\Wallet\Wallet;

interface WalletHandler
{
    /**
     * Use this function to process an ultra buy action.
     *
     * @param int        $userId Hub Culture user identifier.
     * @param UltraAsset $asset
     * @param float      $purchaseAssetAmount
     *
     * @throws InsufficientVenBalanceException
     * @throws InsufficientAssetAvailabilityException
     * @throws WalletException
     */
    public function purchase($userId, UltraAsset $asset, $purchaseAssetAmount);

    /**
     * Use this function to process an ultra sell action.
     *
     * @param int        $userId                     Hub Culture identifier of the selling user.
     * @param UltraAsset $asset                      Ultra asset created by a user.
     * @param float      $sellAssetAmount            Amount of assets that the user is about to sell.
     * @param float|null $customVenAmountForOneAsset A user can propose a different rate instead using the market rate
     *                                               when selling an asset.
     *
     * @throws InsufficientUltraAssetBalanceException
     * @throws WalletException
     */
    public function sell($userId, UltraAsset $asset, $sellAssetAmount, $customVenAmountForOneAsset = null);

    /**
     * @param Wallet $senderWallet
     * @param int    $receiverId Hub Culture receiver user identifier.
     * @param float  $purchaseAssetAmount
     * @param string $message    [optional]
     *
     * @throws InsufficientBalanceException
     */
    public function gift(Wallet $senderWallet, $receiverId, $purchaseAssetAmount, $message = '');
}
