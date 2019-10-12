<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 09-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Asset\IssuerSelectionStrategy;
use Hub\UltraCore\Exception\WalletException;
use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Exchange;
use Hub\UltraCore\Money\Money;
use Hub\UltraCore\Exception\InsufficientAssetAvailabilityException;
use Hub\UltraCore\Exception\InsufficientBalanceException;
use Hub\UltraCore\Exception\InsufficientUltraAssetBalanceException;
use Hub\UltraCore\Exception\InsufficientVenBalanceException;
use Hub\UltraCore\Ven\UltraVenRepository;
use Hub\UltraCore\Wallet\Wallet;
use Hub\UltraCore\Wallet\WalletRepository;

class DefaultWalletHandler implements WalletHandler
{
    /**
     * @var UltraVenRepository
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
     * @var Exchange
     */
    private $exchange;

    /**
     * @var IssuerSelectionStrategy
     */
    private $assetSelectionStrategy;

    /**
     * @param UltraVenRepository      $venRepository
     * @param UltraAssetsRepository   $ultraAssetsRepository
     * @param WalletRepository        $walletRepository
     * @param Exchange                $exchange
     * @param IssuerSelectionStrategy $assetSelectionStrategy
     */
    public function __construct(
        UltraVenRepository $venRepository,
        UltraAssetsRepository $ultraAssetsRepository,
        WalletRepository $walletRepository,
        Exchange $exchange,
        IssuerSelectionStrategy $assetSelectionStrategy
    ) {
        $this->venRepository = $venRepository;
        $this->ultraAssetsRepository = $ultraAssetsRepository;
        $this->walletRepository = $walletRepository;
        $this->exchange = $exchange;
        $this->assetSelectionStrategy = $assetSelectionStrategy;
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
        $venWallet = $this->venRepository->getVenWalletOfUser($userId);
        $venAmountForOneAsset = $this->exchange->convertToVen(new Money(1, $asset->getCurrency()))->getAmount();

        // ven balance validation : do not let them pay ven they don't have in their balance
        $totalVenAmountForAssets = ($venAmountForOneAsset * $purchaseAssetAmount);
        if ($totalVenAmountForAssets > $venWallet->getBalance()) {
            throw new InsufficientVenBalanceException(sprintf(
                'Current VEN Balance of \'%s\' is not sufficient to buy the assets worth \'%s\' VEN',
                $venWallet->getBalance(),
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
        $metaData['asset_amount_for_one_ven'] = $this->exchange
            ->convertFromVenToOther(new Money(1, Currency::VEN()), $asset->getCurrency())
            ->getAmountAsString();
        $metaData['ven_amount_for_one_asset'] = $venAmountForOneAsset;
        $metaData['weightingConfig'] = $weightingConfig;
        $metaData['commit'] = true;

        $wallet = $this->walletRepository->getUserWallet($userId, $asset->id());

        $this->walletRepository->credit($wallet, $purchaseAssetAmount, $metaData);

        // deduct the number of available assets now as we credited some for a user's wallet.
        $this->ultraAssetsRepository->deductTotalAssetQuantityBy($asset->id(), $purchaseAssetAmount);

        $issuers = $this->assetSelectionStrategy->select($asset, $purchaseAssetAmount);
        foreach ($issuers as $issuer) {
            $this->ultraAssetsRepository->deductAssetQuantityBy($issuer->getAuthorityUserId(), $asset->id(), $issuer->getUsableAssetQuantity());
            // reduce the amount paid in VEN from the user's VEN account and credit it to the each asset issuer account.
            $venMessage = "Purchased an amount of {$issuer->getUsableAssetQuantity()} {$asset->title()} assets @{$venAmountForOneAsset} VEN per 1 {$asset->title()}. Click <a href='/markets/my-wallets/transactions?id={$wallet->getId()}'>here</a> for more info.";
            $this->venRepository->sendVen(
                $userId,
                $issuer->getAuthorityUserId(),
                ($venAmountForOneAsset * $issuer->getUsableAssetQuantity()),
                $venMessage
            );
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
        $wallet = $this->walletRepository->getUserWallet($userId, $asset->id());

        // ven balance validation : do not let them pay ven they don't have in their balance
        if ($sellAssetAmount > $wallet->getAvailableBalance()) {
            throw new InsufficientUltraAssetBalanceException(sprintf(
                'Current asset balance of \'%s\' is not sufficient to sell %s of assets',
                $wallet->getAvailableBalance(),
                $sellAssetAmount
            ));
        }

        $weightingConfig = [];
        array_walk($asset->weightings(), function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
            $weightingConfig[] = $weighting->toArray();
        });

        $metaData = [];
        $venAmountForOneAsset = $this->exchange->convertToVen(new Money(1, $asset->getCurrency()))->getAmount();
        $metaData['asset_amount_in_ven'] = ($venAmountForOneAsset * $sellAssetAmount);
        $metaData['asset_amount_for_one_ven'] = $this->exchange
            ->convertFromVenToOther(new Money(1, Currency::VEN()), $asset->getCurrency())
            ->getAmountAsString();
        $metaData['ven_amount_for_one_asset'] = $venAmountForOneAsset;
        $metaData['weightingConfig'] = $weightingConfig;
        $metaData['commit'] = false; // sell orders are always processed / committed by an admin for now until we integrate Kraken or other exchange.

        $this->walletRepository->debit($wallet, $sellAssetAmount, $metaData);

        // TODO: implement kraken exchange functions to process the transaction immediately
    }

    /**
     * @param Wallet $senderWallet
     * @param int    $receiverId
     * @param float  $purchaseAssetAmount
     * @param string $message [optional]
     *
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

        $venAmountForOneAsset = $this->exchange->convertToVen(new Money(1, $asset->getCurrency()))->getAmount();
        $metaData['asset_amount_in_ven'] = ($venAmountForOneAsset * $purchaseAssetAmount);
        $metaData['asset_amount_for_one_ven'] = $this->exchange
            ->convertFromVenToOther(new Money(1, Currency::VEN()), $asset->getCurrency())
            ->getAmountAsString();
        $metaData['ven_amount_for_one_asset'] = $venAmountForOneAsset;
        $metaData['weightingConfig'] = $weightingConfig;
        $metaData['is_transfer'] = 1; // this is to mark this as a fund transfer
        $metaData['transfer_message'] = $message;
        $metaData['commit'] = true;

        $metaData['transfer_related_user'] = $senderWallet->getUserId();
        $this->walletRepository->credit($receiverWallet, $purchaseAssetAmount, $metaData);

        $metaData['transfer_related_user'] = $receiverId;
        $this->walletRepository->debit($senderWallet, $purchaseAssetAmount, $metaData);
    }
}
