<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\Issuance\IssuerSelectionStrategy;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\MatchEngine\Order\Orders;
use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Exchange;
use Hub\UltraCore\Money\Money;
use Hub\UltraCore\UltraAsset;
use Hub\UltraCore\UltraAssetsRepository;
use Hub\UltraCore\UltraAssetWeighting;
use Hub\UltraCore\Ven\UltraVenRepository;
use Hub\UltraCore\Wallet\WalletRepository;
use Psr\Log\LoggerInterface;

class BuyerIssuerMatcher
{
    /**
     * @var Exchange
     */
    private $exchange;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var IssuerSelectionStrategy
     */
    private $assetSelectionStrategy;

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
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var int
     */
    private $buyOrderMatchThreshold;

    /**
     * BuyerIssuerMatcher constructor.
     *
     * @param Exchange                $exchange
     * @param OrderRepository         $orderRepository
     * @param IssuerSelectionStrategy $assetSelectionStrategy
     * @param UltraVenRepository      $venRepository
     * @param UltraAssetsRepository   $ultraAssetsRepository
     * @param WalletRepository        $walletRepository
     * @param LoggerInterface         $logger
     * @param int                     $buyOrderMatchThreshold
     */
    public function __construct(
        Exchange $exchange,
        OrderRepository $orderRepository,
        IssuerSelectionStrategy $assetSelectionStrategy,
        UltraVenRepository $venRepository,
        UltraAssetsRepository $ultraAssetsRepository,
        WalletRepository $walletRepository,
        LoggerInterface $logger,
        $buyOrderMatchThreshold = Orders::MAX_MATCH_ATTEMPTS_PER_BUY_ORDER
    ) {
        $this->exchange = $exchange;
        $this->orderRepository = $orderRepository;
        $this->assetSelectionStrategy = $assetSelectionStrategy;
        $this->venRepository = $venRepository;
        $this->ultraAssetsRepository = $ultraAssetsRepository;
        $this->walletRepository = $walletRepository;
        $this->logger = $logger;
        $this->buyOrderMatchThreshold = $buyOrderMatchThreshold;
    }

    /**
     * let's retrieve any pending buy orders to see if we have gone through the match attempt threshold.
     * If so, we need to settle buyers by buying off directly from the asset (authority) issuer / minter.
     *
     * Validations:
     * This makes sure the issuer still have ULTRA assets left in their reserve to give to buyers.
     * This also makes sure the buy has enough money in Ven to buy ULTRA assets.
     *
     * This reduces the global asset available quantity as we buy off from the minters' allotment than from a seller.
     */
    public function execute()
    {
        $orders = $this->orderRepository->getPendingOrders();
        $buyOrders = $orders->getBuyOrders();
        foreach ($buyOrders as $buyOrder) {
            $this->logger->info(sprintf('Processing buy order %s', (string)$buyOrder), [__CLASS__]);

            // do not buy off of issuers directly if enough match attempts has NOT made
            if ($buyOrder->getNumMatchAttempts() < $this->buyOrderMatchThreshold) {
                $this->logger->debug("Buy order [{$buyOrder->getId()}] has not reached the threshold [{$this->buyOrderMatchThreshold}] to be settled via an issuer");
                continue;
            }

            $asset = $this->ultraAssetsRepository->getAssetById($buyOrder->getAssetId());
            if (is_null($asset)) {
                $this->logger->warning("A valid ULTRA asset cannot be found for the asset id {$buyOrder->getAssetId()}", [__CLASS__]);
                continue;
            }

            $venWallet = $this->venRepository->getVenWalletOfUser($buyOrder->getUserId());
            if (is_null($venWallet)) {
                $this->orderRepository->rejectOrder($buyOrder->getId(), 'Cannot find the Ven wallet');
                $this->logger->debug('Cannot find the Ven wallet of the buyer', [__CLASS__]);
                continue;
            }

            $venAmountForOneAsset = $this->getVenAmountForOneAsset($asset);
            $purchaseAssetAmount = $buyOrder->getAmount();

            // ven balance validation : do not let them pay ven they don't have in their balance
            $totalVenAmountForAssets = ($venAmountForOneAsset * $purchaseAssetAmount);
            if ($totalVenAmountForAssets > $venWallet->getBalance()) {
                $rejectionReason = sprintf(
                    'Current VEN Balance of \'%s\' is not sufficient to buy the assets worth \'%s\' VEN',
                    $venWallet->getBalance(),
                    $totalVenAmountForAssets
                );
                $this->logger->debug($rejectionReason, [__CLASS__]);
                $this->orderRepository->rejectOrder($buyOrder->getId(), $rejectionReason);
                continue;
            }

            // asset balance validation : do not let anyone buy more than the available number of assets
            $newAssetBalance = $asset->numAssets() - $purchaseAssetAmount;
            if ($newAssetBalance < 0) {
                $rejectionReason = sprintf(
                    'There are no such amount of assets available for the buyer\'s requested amount of %s. Only %s available now.',
                    $purchaseAssetAmount,
                    $asset->numAssets()
                );
                $this->logger->debug($rejectionReason, [__CLASS__]);
                $this->orderRepository->rejectOrder($buyOrder->getId(), $rejectionReason);
                continue;
            }

            // now that we are done with validations for funds and wallet integrity. let's try to find a issuer authority
            $issuers = $this->assetSelectionStrategy->select($asset->id(), $buyOrder->getAmount());
            $this->logger->debug(
                sprintf("Found [%d] issuers for the buy order [%d]", count($issuers), $buyOrder->getId()),
                [__CLASS__]
            );
            if (empty($issuers)) {
                $this->logger->warning("No authority issuers found. skipping this order", [__CLASS__]);
                continue;
            }

            $weightingConfig = [];
            $weightings = $asset->weightings();
            array_walk($weightings, function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
                $weightingConfig[] = $weighting->toArray();
            });
            $metaData = [];
            $metaData[MatchedOrderMetaData::ASSET_AMOUNT_IN_VEN] = $totalVenAmountForAssets;
            $metaData['asset_amount_for_one_ven'] = $this->exchange
                ->convertFromVenToOther(new Money(1, Currency::VEN()), $asset->getCurrency())
                ->getAmountAsString();
            $metaData[MatchedOrderMetaData::VEN_AMOUNT_FOR_ONE_ASSET] = $venAmountForOneAsset;
            $metaData['weightingConfig'] = $weightingConfig;

            $buyerWallet = $this->walletRepository->getUserWallet($buyOrder->getUserId(), $asset->id());
            $this->walletRepository->credit($buyerWallet, $buyOrder->getAmount(), $metaData);

            // deduct the number of available assets now as we credited some for this buyer's wallet.
            $this->ultraAssetsRepository->deductTotalAssetQuantityBy($asset->id(), $buyOrder->getAmount());

            foreach ($issuers as $issuer) {
                $this->ultraAssetsRepository->deductAssetQuantityBy(
                    $issuer->getAuthorityUserId(),
                    $asset->id(),
                    $issuer->getSaleableAssetQuantity()
                );

                // reduce the amount paid in VEN from the user's VEN account and credit it to the each asset issuer account.
                $venMessage = "Purchased an amount of {$issuer->getSaleableAssetQuantity()} {$asset->title()} assets @{$venAmountForOneAsset} VEN per 1 {$asset->title()}. Click <a href='/markets/my-wallets/transactions?id={$buyerWallet->getId()}'>here</a> for more info.";
                $this->venRepository->sendVen(
                    $buyOrder->getUserId(),
                    $issuer->getAuthorityUserId(),
                    ($venAmountForOneAsset * $issuer->getSaleableAssetQuantity()),
                    $venMessage
                );
            }

            $this->orderRepository->processOrder($buyOrder->getId());
            $this->logger->info(sprintf("Done processing this buy order [%d]", $buyOrder->getId()), [__CLASS__]);
        }

        $sellOrders = $orders->getSellOrders();
        foreach ($sellOrders as $sellOrder) {
            $this->logger->info(sprintf('Processing sell order %s', (string)$sellOrder), [__CLASS__]);

            // do not sell to the system user if enough match attempts has NOT made
            if ($sellOrder->getNumMatchAttempts() < $this->buyOrderMatchThreshold) {
                $this->logger->debug("Sell order [{$sellOrder->getId()}] has not reached the threshold [{$this->buyOrderMatchThreshold}] to be settled via an issuer");
                continue;
            }

            $asset = $this->ultraAssetsRepository->getAssetById($sellOrder->getAssetId());
            if (is_null($asset)) {
                $this->logger->warning("A valid ULTRA asset cannot be found for the asset id {$sellOrder->getAssetId()}", [__CLASS__]);
                continue;
            }

            $sellAssetAmount = $sellOrder->getAmount();

            // asset balance validation : do not let anyone sell more than the available number of assets in their wallet
            $sellerWallet = $this->walletRepository->getUserWallet($sellOrder->getUserId(), $sellOrder->getAssetId());
            if (($sellerWallet->getAvailableBalance() - $sellAssetAmount) < 0) {
                $rejectionReason = sprintf(
                    'There is no enough balance in the seller\'s wallet, \'%s\' to sell %s. Only %s available now.',
                    $sellerWallet->getPublicKey(),
                    $sellAssetAmount,
                    $sellerWallet->getAvailableBalance()
                );
                $this->logger->debug($rejectionReason, [__CLASS__]);
                $this->orderRepository->rejectOrder($sellOrder->getId(), $rejectionReason);
                continue;
            }

            $venAmountForOneAsset = $this->getVenAmountForOneAsset($asset);
            $totalVenAmountForAssets = ($venAmountForOneAsset * $sellAssetAmount);

            $weightingConfig = [];
            $weightings = $asset->weightings();
            array_walk($weightings, function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
                $weightingConfig[] = $weighting->toArray();
            });
            $metaData = [];
            $metaData[MatchedOrderMetaData::ASSET_AMOUNT_IN_VEN] = $totalVenAmountForAssets;
            $metaData['asset_amount_for_one_ven'] = $this->exchange
                ->convertFromVenToOther(new Money(1, Currency::VEN()), $asset->getCurrency())
                ->getAmountAsString();
            $metaData[MatchedOrderMetaData::VEN_AMOUNT_FOR_ONE_ASSET] = $venAmountForOneAsset;
            $metaData['weightingConfig'] = $weightingConfig;

            $this->walletRepository->debit($sellerWallet, $sellAssetAmount, $metaData);

            // let's send this amount to the system user as we couln't
            $this->walletRepository->credit(
                $this->walletRepository->getUserWallet(MatchEngine::SYSTEM_USER_ID, $asset->id()),
                $sellAssetAmount,
                $metaData
            );

            // reduce the amount paid in VEN from the user's VEN account and credit it to the each asset issuer account.
            $venMessage = "Sold an amount of {$sellAssetAmount} {$asset->title()} assets @{$venAmountForOneAsset} VEN per 1 {$asset->title()}. Click <a href='/markets/my-wallets/transactions?id={$sellerWallet->getId()}'>here</a> for more info.";
            $this->venRepository->sendVen(
                MatchEngine::SYSTEM_USER_ID,
                $sellOrder->getUserId(),
                $totalVenAmountForAssets,
                $venMessage
            );

            $this->orderRepository->processOrder($sellOrder->getId());
            $this->logger->info(sprintf("Done processing this sell order [%d]", $sellOrder->getId()), [__CLASS__]);
        }
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
