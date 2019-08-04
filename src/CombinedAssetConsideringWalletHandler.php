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
     * @param WalletHandler $innerWalletHandler
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
     * Purchases a requested amount from the given asset and credits it into the given user's asset wallet.
     *
     * @param int $userId
     * @param UltraAsset $asset
     * @param float $purchaseAssetAmount
     * @throws InsufficientVenBalanceException
     * @throws InsufficientAssetAvailabilityException
     */
    public function purchase($userId, UltraAsset $asset, $purchaseAssetAmount)
    {
        $similarAssets = $this->ultraAssetsRepository->getQuantitiesPerSimilarAsset($asset, $purchaseAssetAmount);
        foreach ($similarAssets as $similarAsset) {
            $this->innerWalletHandler->purchase($userId, $similarAsset['asset'], $similarAsset['quantity']);
        }
    }

    /**
     * @param int $userId
     * @param UltraAsset $asset
     * @param float $sellAssetAmount
     */
    public function sell($userId, UltraAsset $asset, $sellAssetAmount)
    {
        $this->innerWalletHandler->sell($userId, $asset, $sellAssetAmount);
    }

    /**
     * Transfer some asset amount to a friend. This will debit the sender's wallet with the equivalent amount.
     *
     * @param Wallet $senderWallet
     * @param int $receiverId
     * @param float $transferAssetAmount
     * @param string $message [optional]
     * @throws InsufficientBalanceException
     */
    public function gift(Wallet $senderWallet, $receiverId, $transferAssetAmount, $message = '')
    {
        $this->innerWalletHandler->gift($senderWallet, $receiverId, $transferAssetAmount, $message);
    }
}
