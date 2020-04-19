<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 09-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Exception\WalletException;
use Hub\UltraCore\MatchEngine\MatchedOrderMetaData;
use Hub\UltraCore\MatchEngine\MatchEngine;
use Hub\UltraCore\MatchEngine\Order\BuyOrder;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\MatchEngine\Order\Orders;
use Hub\UltraCore\MatchEngine\Order\SellOrder;
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
     * @var OrderRepository
     */
    private $orderRepository;


    /**
     * @var Exchange
     */
    private $exchange;

    /**
     * @param UltraVenRepository    $venRepository
     * @param UltraAssetsRepository $ultraAssetsRepository
     * @param WalletRepository      $walletRepository
     * @param OrderRepository       $orderRepository
     * @param Exchange              $exchange
     */
    public function __construct(
        UltraVenRepository $venRepository,
        UltraAssetsRepository $ultraAssetsRepository,
        WalletRepository $walletRepository,
        OrderRepository $orderRepository,
        Exchange $exchange
    ) {
        $this->venRepository = $venRepository;
        $this->ultraAssetsRepository = $ultraAssetsRepository;
        $this->walletRepository = $walletRepository;
        $this->orderRepository = $orderRepository;
        $this->exchange = $exchange;
    }

    /**
     * Use this function to process an ultra buy action.
     *
     * @param int        $userId                     Hub Culture identifier of the purchasing user.
     * @param UltraAsset $asset
     * @param float      $purchaseAssetAmount
     * @param float      $customVenAmountForOneAsset A user can propose a different rate instead using the market rate
     *                                               when buying an asset. The buyer is willing to pay this much in Ven
     *                                               for one ULTRA asset.
     *
     * @throws InsufficientVenBalanceException
     * @throws InsufficientAssetAvailabilityException
     * @throws WalletException
     */
    public function purchase($userId, UltraAsset $asset, $purchaseAssetAmount, $customVenAmountForOneAsset = 0.0)
    {
        if (floatval($purchaseAssetAmount) <= 0.0) {
            throw new WalletException("Purchase amount must be greater than zero(0)");
        }

        $venWallet = $this->venRepository->getVenWalletOfUser($userId);
        if (is_null($venWallet)) {
            throw new WalletException(sprintf("Cannot find the Ven wallet for the given user : %s", $userId));
        }

        $venAmountForOneAsset = $this->getVenAmountForOneAsset($asset);

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

        if (floatval($customVenAmountForOneAsset) <= 0.0) {
            $customVenAmountForOneAsset = $venAmountForOneAsset;
        }

        // and then we can add this sell order to be processed asynchronously later when matched with a buy order
        $this->orderRepository->addBuyOrder(
            BuyOrder::newPendingOrder(
                $userId,
                $asset->id(),
                $customVenAmountForOneAsset,
                $purchaseAssetAmount
            ),
            $venAmountForOneAsset
        );
    }

    /**
     * Use this function to process an ultra sell action.
     *
     * @param int        $userId                     Hub Culture identifier of the selling user.
     * @param UltraAsset $asset                      Ultra asset created by a user.
     * @param float      $sellAssetAmount            Amount of assets that the user is about to sell.
     * @param float      $customVenAmountForOneAsset A user can propose a different rate instead using the market rate
     *                                               when selling an asset.
     *
     * @throws InsufficientUltraAssetBalanceException
     * @throws WalletException
     */
    public function sell($userId, UltraAsset $asset, $sellAssetAmount, $customVenAmountForOneAsset = 0.0)
    {
        if (floatval($sellAssetAmount) <= 0.0) {
            throw new WalletException("Purchase amount must be greater than zero(0)");
        }

        $wallet = $this->walletRepository->getUserWallet($userId, $asset->id());

        // ven balance validation : do not let them pay ven they don't have in their balance
        if ($sellAssetAmount > $wallet->getAvailableBalance()) {
            throw new InsufficientUltraAssetBalanceException(sprintf(
                "You do not have enough %s balance to sell an amount of %s assets. You currently have %s in your %s wallet.",
                $asset->tickerSymbol(),
                $sellAssetAmount,
                $wallet->getAvailableBalance(),
                $asset->tickerSymbol()
            ));
        }

        $venAmountForOneAsset = $this->getVenAmountForOneAsset($asset);
        if (floatval($customVenAmountForOneAsset) <= 0.0) {
            $customVenAmountForOneAsset = $venAmountForOneAsset;
        }

        // and then we can add this sell order to be processed asynchronously later when matched with a buy order
        $this->orderRepository->addSellOrder(
            SellOrder::newPendingOrder(
                $userId,
                $asset->id(),
                $customVenAmountForOneAsset,
                // the actual selling amount is after deducting our fees
                $sellAssetAmount
            ),
            $venAmountForOneAsset
        );
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

        $venAmountForOneAsset = $this->getVenAmountForOneAsset($asset);
        $metaData[MatchedOrderMetaData::ASSET_AMOUNT_IN_VEN] = ($venAmountForOneAsset * $purchaseAssetAmount);
        $metaData['asset_amount_for_one_ven'] = $this->exchange
            ->convertFromVenToOther(new Money(1, Currency::VEN()), $asset->getCurrency())
            ->getAmountAsString();
        $metaData[MatchedOrderMetaData::VEN_AMOUNT_FOR_ONE_ASSET] = $venAmountForOneAsset;
        $metaData['weightingConfig'] = $weightingConfig;
        $metaData['is_transfer'] = 1; // this is to mark this as a fund transfer
        $metaData['transfer_message'] = $message;
        $metaData['commit'] = true;

        $metaData['transfer_related_user'] = $senderWallet->getUserId();
        $this->walletRepository->credit($receiverWallet, $purchaseAssetAmount, $metaData);

        $metaData['transfer_related_user'] = $receiverId;
        $this->walletRepository->debit($senderWallet, $purchaseAssetAmount, $metaData);
    }

    /**
     * This returns the amount that a user needs to pay in Ven to buy/sell an Ultra asset.
     *
     * @param UltraAsset $asset The ultra asset that needs calculating.
     *
     * @return float The amount in ven required to buy one 1 Ultra asset.
     */
    private function getVenAmountForOneAsset(UltraAsset $asset)
    {
        $weightings = $asset->weightings();

        /**
         * Let's determine the price of the asset in Ven.
         * If the pricing model is not based on a currency composition, it is always a ones with a custom Ven amount.
         * In such a case where the price is set as a weighting by the UltraAssetFactory.
         * @see UltraAssetFactory::extractAssetWeightings()
         */
        if ($asset->weightingType() !== UltraAssetsRepository::TYPE_CURRENCY_COMBO && $asset->isWithOneWeighting()) {
            /** @var UltraAssetWeighting $weighting */
            $weighting = array_shift($weightings);
            $venAmountForOneAsset = $weighting->currencyAmount();
        } else {
            $venAmountForOneAsset = $this->exchange->convertToVen(new Money(1, $asset->getCurrency()))->getAmount();
        }

        return $venAmountForOneAsset;
    }
}
