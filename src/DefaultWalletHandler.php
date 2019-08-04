<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 09-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Repository\VenRepository;
use Hub\UltraCore\Repository\WalletRepository;
use Hub\UltraCore\Model\Eloquent\Wallet;
use Hub\UltraCore\Exception\InsufficientAssetAvailabilityException;
use Hub\UltraCore\Exception\InsufficientBalanceException;
use Hub\UltraCore\Exception\InsufficientVenBalanceException;

class DefaultWalletHandler implements WalletHandler
{
    /**
     * @var VenRepository
     */
    private $venRepository;

    /**
     * @var UltraAssetsRepository
     */
    private $ultraAssetsRepository;

    /**
     * @var WalletRepository
     */
    private $walletRepository;

    /**
     * @param VenRepository $venRepository
     * @param UltraAssetsRepository $ultraAssetsRepository
     * @param WalletRepository $walletRepository
     */
    public function __construct(
        VenRepository $venRepository,
        UltraAssetsRepository $ultraAssetsRepository,
        WalletRepository $walletRepository
    ) {
        $this->venRepository = $venRepository;
        $this->ultraAssetsRepository = $ultraAssetsRepository;
        $this->walletRepository = $walletRepository;
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
        $venBalance = $this->venRepository->getVenBalanceOfUser($userId);
        $venAmountForOneAsset = $this->ultraAssetsRepository->getVenAmountForOneAsset($asset);

        // ven balance validation : do not let them pay ven they don't have in their balance
        $totalVenAmountForAssets = ($venAmountForOneAsset * $purchaseAssetAmount);
        if ($totalVenAmountForAssets > $venBalance->balance()) {
            throw new InsufficientVenBalanceException(sprintf(
                'Current VEN Balance of \'%s\' is not sufficient to buy the assets worth \'%s\' VEN',
                $venBalance->balance(),
                $totalVenAmountForAssets
            ));
        }

        // asset balance validation : do not let anyone buy more than the available number of assets
        $newAssetBalance = $asset->numAssets() - $purchaseAssetAmount;
        if ($newAssetBalance < 0) {
            throw new InsufficientAssetAvailabilityException(sprintf(
                'There are no such amount of assets available for your requested amount of %s. Only %s available.',
                $purchaseAssetAmount,
                $asset->numAssets()
            ));
        }

        $weightingConfig = [];
        $weightings = $asset->weightings();
        array_walk($weightings, function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
            $weightingConfig[] = $weighting->toArray();
        });

        $metaData = [];
        $metaData['asset_amount_in_ven'] = $totalVenAmountForAssets;
        $metaData['asset_amount_for_one_ven'] = $this->ultraAssetsRepository->getAssetAmountForOneVen($asset);
        $metaData['ven_amount_for_one_asset'] = $venAmountForOneAsset;
        $metaData['weightingConfig'] = $weightingConfig;

        $wallet = $this->walletRepository->getUserWallet($userId, $asset->id());

        $this->walletRepository->credit($wallet, $purchaseAssetAmount, $metaData);

        // deduct the number of available assets now as we credited some for a user's wallet.
        $this->ultraAssetsRepository->deductAssetQuantityBy($asset->id(), $purchaseAssetAmount);

        // reduce the amount paid in VEN from the user's VEN account and credit it to the asset issuer account.
        $venMessage = "Purchased an amount of {$purchaseAssetAmount} {$asset->title()} assets @{$venAmountForOneAsset} VEN per 1 {$asset->title()}. Click <a href='/markets/my-wallets/transactions?id={$wallet->getId()}'>here</a> for more info.";
        $this->venRepository->sendVen($userId, $asset->authorityUserId(), $totalVenAmountForAssets, $venMessage);
    }

    /**
     * @todo : integrate erc20 system here to sell ultra assets
     * @param int $userId
     * @param UltraAsset $asset
     * @param float $purchaseAssetAmount
     */
    public function sell($userId, UltraAsset $asset, $purchaseAssetAmount)
    {
        $weightingConfig = [];
        array_walk($asset->weightings(), function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
            $weightingConfig[] = $weighting->toArray();
        });

        $metaData = [];
        $metaData['weightingConfig'] = $weightingConfig;

        $wallet = $this->walletRepository->getUserWallet($userId, $asset->id());
        $this->walletRepository->debit($wallet, $purchaseAssetAmount, $metaData);

        // @TODO: calculate VEN. TBD
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
    public function gift(Wallet $senderWallet, $receiverId, $purchaseAssetAmount, $message = '')
    {
        $asset = $this->ultraAssetsRepository->getAssetById($senderWallet->getAssetId());
        $receiverWallet = $this->walletRepository->getUserWallet($receiverId, $senderWallet->getAssetId());

        // wallet balance validation : do not let them send funds they don't have in their wallet
        if ($purchaseAssetAmount > $senderWallet->getBalance()) {
            throw new InsufficientBalanceException(sprintf(
                'Current balance of \'%s\' is not sufficient to send \'%s\' %s',
                $senderWallet->getBalance(),
                $purchaseAssetAmount,
                $asset->tickerSymbol()
            ));
        }

        $weightingConfig = [];
        $weightings = $asset->weightings();
        array_walk($weightings, function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
            $weightingConfig[] = $weighting->toArray();
        });

        $venAmountForOneAsset = $this->ultraAssetsRepository->getVenAmountForOneAsset($asset);
        $metaData['asset_amount_in_ven'] = ($venAmountForOneAsset * $purchaseAssetAmount);
        $metaData['asset_amount_for_one_ven'] = $this->ultraAssetsRepository->getAssetAmountForOneVen($asset);
        $metaData['ven_amount_for_one_asset'] = $venAmountForOneAsset;
        $metaData['weightingConfig'] = $weightingConfig;
        $metaData['is_transfer'] = 1; // this is to mark this as a fund transfer
        $metaData['transfer_message'] = $message;

        $metaData['transfer_related_user'] = $senderWallet->getUserId();
        $this->walletRepository->credit($receiverWallet, $purchaseAssetAmount, $metaData);

        $metaData['transfer_related_user'] = $receiverId;
        $this->walletRepository->debit($senderWallet, $purchaseAssetAmount, $metaData);
    }
}
