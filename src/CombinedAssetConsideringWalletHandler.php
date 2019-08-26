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

class CombinedAssetConsideringWalletHandler implements WalletHandler
{
    /**
     * @var WalletHandler
     */
    private $innerWalletHandler;

    /**
     * @var UltraAssetsRepository
     */
    private $ultraAssetsRepository;

    /**
     * @param WalletHandler         $innerWalletHandler
     * @param UltraAssetsRepository $ultraAssetsRepository
     */
    public function __construct(
        WalletHandler $innerWalletHandler,
        UltraAssetsRepository $ultraAssetsRepository
    ) {
        $this->innerWalletHandler = $innerWalletHandler;
        $this->ultraAssetsRepository = $ultraAssetsRepository;
    }

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
    public function purchase($userId, UltraAsset $asset, $purchaseAssetAmount)
    {
        $similarAssets = $this->ultraAssetsRepository->getQuantitiesPerSimilarAsset($asset, $purchaseAssetAmount);
        foreach ($similarAssets as $similarAsset) {
            $this->innerWalletHandler->purchase($userId, $similarAsset['asset'], $similarAsset['quantity']);
        }
    }

    /**
     * Use this function to process an ultra sell action.
     *
     * @param int        $userId          Hub Culture user identifier.
     * @param UltraAsset $asset           Ultra asset created by a user.
     * @param float      $sellAssetAmount Amount of assets that the user is about to sell.
     *
     * @throws InsufficientUltraAssetBalanceException
     * @throws WalletException
     */
    public function sell($userId, UltraAsset $asset, $sellAssetAmount)
    {
        $this->innerWalletHandler->sell($userId, $asset, $sellAssetAmount);
    }

    /**
     * @param Wallet $senderWallet
     * @param int    $receiverId Hub Culture receiver user identifier.
     * @param float  $purchaseAssetAmount
     * @param string $message    [optional]
     *
     * @throws InsufficientBalanceException
     */
    public function gift(Wallet $senderWallet, $receiverId, $purchaseAssetAmount, $message = '')
    {
        $this->innerWalletHandler->gift($senderWallet, $receiverId, $purchaseAssetAmount, $message);
    }
}
