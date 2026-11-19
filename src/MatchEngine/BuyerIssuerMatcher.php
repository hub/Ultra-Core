<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 */

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\Exception\InsufficientBalanceException;
use Hub\UltraCore\Exception\VenTransferException;
use Hub\UltraCore\Exception\WalletException;
use Hub\UltraCore\Issuance\AssetIssuerAuthority;
use Hub\UltraCore\Issuance\IssuerSelectionStrategy;
use Hub\UltraCore\MatchEngine\Order\BuyOrder;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\MatchEngine\Order\Orders;
use Hub\UltraCore\MatchEngine\Order\SellOrder;
use Hub\UltraCore\MatchEngine\Settlement\SettlementGuard;
use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Exchange;
use Hub\UltraCore\Money\Money;
use Hub\UltraCore\UltraAsset;
use Hub\UltraCore\UltraAssetsRepository;
use Hub\UltraCore\UltraAssetWeighting;
use Hub\UltraCore\Ven\UltraVenRepository;
use Hub\UltraCore\Ven\VenLedger;
use Hub\UltraCore\Wallet\WalletRepository;
use Psr\Log\LoggerInterface;

/**
 * Settles what the order book couldn't, after the match-attempt threshold:
 *   buy orders  from the issuers' supply, at the asset price;
 *   sell orders by the system account (user 1) buying them back at the asset price, except bonds
 *               (SystemBuyBackPolicy).
 *
 * Every order is claimed (pending -> processing) and its settlement key written before any money moves, so a
 * second run, or a re-run after a crash, never pays for it again (SettlementGuard). When the state of the money
 * is uncertain the order is flagged for review and the run stops: it is never retried automatically.
 */
class BuyerIssuerMatcher
{
    /** A market order fills only within 0.5% of the price the buyer was quoted. */
    const PRICE_TOLERANCE = 0.005;

    /** settleBuyOrder() / settleSellOrder() outcomes */
    const DONE = 'done';
    const SKIPPED = 'skipped';
    const STOP = 'stop';

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
     * @var SettlementGuard|null
     */
    private $guard;

    /**
     * @var SystemBuyBackPolicy
     */
    private $buyBackPolicy;

    /**
     * BuyerIssuerMatcher constructor.
     *
     * @param Exchange                 $exchange
     * @param OrderRepository          $orderRepository
     * @param IssuerSelectionStrategy  $assetSelectionStrategy
     * @param UltraVenRepository       $venRepository
     * @param UltraAssetsRepository    $ultraAssetsRepository
     * @param WalletRepository         $walletRepository
     * @param LoggerInterface          $logger
     * @param int                      $buyOrderMatchThreshold
     * @param SettlementGuard|null     $guard         default: on the order repository's connection
     * @param SystemBuyBackPolicy|null $buyBackPolicy default: never buy back bonds
     */
    public function __construct(
        Exchange $exchange,
        OrderRepository $orderRepository,
        IssuerSelectionStrategy $assetSelectionStrategy,
        UltraVenRepository $venRepository,
        UltraAssetsRepository $ultraAssetsRepository,
        WalletRepository $walletRepository,
        LoggerInterface $logger,
        $buyOrderMatchThreshold = Orders::MAX_MATCH_ATTEMPTS_PER_BUY_ORDER,
        ?SettlementGuard $guard = null,
        ?SystemBuyBackPolicy $buyBackPolicy = null
    ) {
        $this->exchange = $exchange;
        $this->orderRepository = $orderRepository;
        $this->assetSelectionStrategy = $assetSelectionStrategy;
        $this->venRepository = $venRepository;
        $this->ultraAssetsRepository = $ultraAssetsRepository;
        $this->walletRepository = $walletRepository;
        $this->logger = $logger;
        $this->buyOrderMatchThreshold = $buyOrderMatchThreshold;
        $this->guard = $guard;
        $this->buyBackPolicy = $buyBackPolicy ?: new SystemBuyBackPolicy($ultraAssetsRepository);
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
     *
     * @return bool false when the run stopped because an order needs a human (flagged for review), or the
     *              settlement schema is missing
     */
    public function execute()
    {
        if (!$this->guard()->isReady()) {
            return false;
        }
        $orders = $this->orderRepository->getPendingOrders();
        foreach ($orders->getBuyOrders() as $buyOrder) {
            $this->logger->info(sprintf('Processing buy order %s', (string)$buyOrder), [__CLASS__]);
            if ($this->settleBuyOrder($buyOrder) === self::STOP) {
                $this->logger->critical('Stopping the issuer settlement run: an order needs review.', [__CLASS__]);

                return false;
            }
        }

        foreach ($orders->getSellOrders() as $sellOrder) {
            $this->logger->info(sprintf('Processing sell order %s', (string)$sellOrder), [__CLASS__]);
            if ($this->settleSellOrder($sellOrder) === self::STOP) {
                $this->logger->critical('Stopping the issuer settlement run: an order needs review.', [__CLASS__]);

                return false;
            }
        }

        return true;
    }

    /**
     * @param BuyOrder $buyOrder
     *
     * @return string DONE, SKIPPED or STOP
     */
    private function settleBuyOrder(BuyOrder $buyOrder)
    {
        // do not buy off of issuers directly if enough match attempts has NOT made
        if ($buyOrder->getNumMatchAttempts() < $this->buyOrderMatchThreshold) {
            $this->logger->debug("Buy order [{$buyOrder->getId()}] has not reached the threshold [{$this->buyOrderMatchThreshold}] to be settled via an issuer");

            return self::SKIPPED;
        }

        $asset = $this->ultraAssetsRepository->getAssetById($buyOrder->getAssetId());
        if (is_null($asset)) {
            $this->logger->warning("A valid ULTRA asset cannot be found for the asset id {$buyOrder->getAssetId()}", [__CLASS__]);

            return self::SKIPPED;
        }

        // only what the order book hasn't filled yet
        $purchaseAssetAmount = round($buyOrder->getAmount() - $buyOrder->getSettledAmountSoFar(), 4);
        if ($purchaseAssetAmount <= 0) {
            return self::SKIPPED;
        }

        $venWallet = $this->venRepository->getVenWalletOfUser($buyOrder->getUserId());
        if (is_null($venWallet)) {
            $this->orderRepository->rejectOrder($buyOrder->getId(), 'Cannot find the Ven wallet');
            $this->logger->debug('Cannot find the Ven wallet of the buyer', [__CLASS__]);

            return self::SKIPPED;
        }

        $venAmountForOneAsset = $this->getVenAmountForOneAsset($asset);

        // Honour the price the buyer agreed: their limit, or for a market order the price they were quoted
        // (stored as the offering rate). If the market is now above it, leave the order waiting.
        $agreedVenPerAsset = (float)$buyOrder->getOfferingRate();
        if ($agreedVenPerAsset > 0 && $venAmountForOneAsset > $agreedVenPerAsset * (1 + self::PRICE_TOLERANCE)) {
            $this->logger->info(sprintf(
                'Buy order [%d] waits: the price %s is above the agreed %s Ven per asset',
                $buyOrder->getId(),
                $venAmountForOneAsset,
                $agreedVenPerAsset
            ), [__CLASS__]);

            return self::SKIPPED;
        }

        // ven balance validation : do not let them pay ven they don't have in their balance
        $totalVenAmountForAssets = VenLedger::toCents($venAmountForOneAsset * $purchaseAssetAmount);
        if ($totalVenAmountForAssets > $venWallet->getBalance()) {
            $rejectionReason = sprintf(
                'Current VEN Balance of \'%s\' is not sufficient to buy the assets worth \'%s\' VEN',
                $venWallet->getBalance(),
                $totalVenAmountForAssets
            );
            $this->logger->debug($rejectionReason, [__CLASS__]);
            $this->orderRepository->rejectOrder($buyOrder->getId(), $rejectionReason);

            return self::SKIPPED;
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

            return self::SKIPPED;
        }

        // now that we are done with validations for funds and wallet integrity. let's try to find a issuer authority
        $issuers = array_values(array_filter(
            $this->assetSelectionStrategy->select($asset->id(), $purchaseAssetAmount),
            function (AssetIssuerAuthority $issuer) {
                return $issuer->getSaleableAssetQuantity() > 0;
            }
        ));
        $this->logger->debug(
            sprintf("Found [%d] issuers for the buy order [%d]", count($issuers), $buyOrder->getId()),
            [__CLASS__]
        );
        if (empty($issuers)) {
            $this->logger->warning("No authority issuers found. skipping this order", [__CLASS__]);

            return self::SKIPPED;
        }
        // the issuers must cover the whole order: never credit assets nobody is selling
        $covered = 0.0;
        foreach ($issuers as $issuer) {
            $covered += $issuer->getSaleableAssetQuantity();
        }
        if (round($covered, 4) < round($purchaseAssetAmount, 4)) {
            $this->logger->warning(sprintf('Issuers cover %s of the %s in buy order [%d]; skipping', $covered, $purchaseAssetAmount, $buyOrder->getId()), [__CLASS__]);

            return self::SKIPPED;
        }

        $buyerWallet = $this->walletRepository->getUserWallet($buyOrder->getUserId(), $asset->id());
        if (is_null($buyerWallet)) {
            $this->logger->error("Buy order [{$buyOrder->getId()}]: the buyer's wallet cannot be opened; skipping", [__CLASS__]);

            return self::SKIPPED;
        }

        $legs = [];
        foreach ($issuers as $issuer) {
            $legs[] = [
                'issuer' => (int)$issuer->getAuthorityUserId(),
                'units' => $issuer->getSaleableAssetQuantity(),
                'ven' => VenLedger::toCents($venAmountForOneAsset * $issuer->getSaleableAssetQuantity()),
            ];
        }
        $metaData = $this->metaData($asset, $venAmountForOneAsset, array_sum(array_column($legs, 'ven')));

        // ---- claim: nothing below runs twice for this order
        $guard = $this->guard();
        $token = $guard->newToken();
        $key = SettlementGuard::orderKey($buyOrder->getId());
        if (!$this->claim($guard, $buyOrder->getId(), $key, 'issuer-buy', $token)) {
            return self::SKIPPED;
        }

        // ---- Ven first: each sendVen takes the Ven only if the buyer still has it
        $paid = [];
        foreach ($legs as $leg) {
            $venMessage = "Purchased an amount of {$leg['units']} {$asset->title()} assets @{$venAmountForOneAsset} VEN per 1 {$asset->title()}. Click <a href='/markets/my-wallets/transactions?id={$buyerWallet->getId()}'>here</a> for more info.";
            try {
                $this->venRepository->sendVen($buyOrder->getUserId(), $leg['issuer'], $leg['ven'], $venMessage);
            } catch (\Exception $e) {
                if ($this->isUncertain($e)) {
                    return $this->review($guard, $buyOrder->getId(), $key, $token, 'Ven payment to issuer ' . $leg['issuer'] . ' has an unknown outcome: ' . $e->getMessage(), $paid);
                }
                if (!$this->refundIssuers($buyOrder, $paid)) {
                    return $this->review($guard, $buyOrder->getId(), $key, $token, 'a payment failed and the issuers already paid could not all be refunded', $paid);
                }
                $guard->failSettlement($key, $token, 'Payment failed, nothing moved: ' . $e->getMessage());
                $guard->rejectOrder($buyOrder->getId(), $token, 'Payment failed: ' . $e->getMessage());

                return self::SKIPPED;
            }
            $paid[] = $leg;
            $guard->recordProgress($key, $token, ['ven_paid' => $paid]);
        }

        // ---- then the assets
        try {
            $this->walletRepository->credit($buyerWallet, $purchaseAssetAmount, $metaData);
        } catch (WalletException $e) {
            // WalletRepository rolled its own write back: the buyer has no units, so the issuers give the Ven back
            if (!$this->refundIssuers($buyOrder, $paid)) {
                return $this->review($guard, $buyOrder->getId(), $key, $token, 'the units could not be credited and the Ven could not all be refunded: ' . $e->getMessage(), $paid);
            }
            $guard->failSettlement($key, $token, 'Units not credited; Ven refunded: ' . $e->getMessage());
            $guard->rejectOrder($buyOrder->getId(), $token, 'The assets could not be credited; your Ven was refunded.');

            return self::SKIPPED;
        } catch (\Exception $e) {
            return $this->review($guard, $buyOrder->getId(), $key, $token, 'the units credit has an unknown outcome after the Ven was paid: ' . $e->getMessage(), $paid);
        }

        $supplyUpdated = $this->ultraAssetsRepository->deductTotalAssetQuantityBy($asset->id(), $purchaseAssetAmount) !== false;
        foreach ($issuers as $issuer) {
            $supplyUpdated = $this->ultraAssetsRepository->deductAssetQuantityBy(
                $issuer->getAuthorityUserId(),
                $asset->id(),
                $issuer->getSaleableAssetQuantity()
            ) !== false && $supplyUpdated;
        }
        if (!$supplyUpdated) {
            // the buyer paid and has the units; only the supply counters are off, so an issuer could oversell
            return $this->review($guard, $buyOrder->getId(), $key, $token, 'settled (Ven paid, units credited) but the asset supply could not be reduced', $paid);
        }

        $guard->completeSettlement($key, $token, sprintf('%s units for %s Ven', $purchaseAssetAmount, $metaData[MatchedOrderMetaData::ASSET_AMOUNT_IN_VEN]));
        $guard->finishOrder($buyOrder->getId(), $token);
        $this->logger->info(sprintf("Done processing this buy order [%d]", $buyOrder->getId()), [__CLASS__]);

        return self::DONE;
    }

    /**
     * The system account buys an unmatched sell order back at the asset price (never a bond, by default).
     *
     * @param SellOrder $sellOrder
     *
     * @return string DONE, SKIPPED or STOP
     */
    private function settleSellOrder(SellOrder $sellOrder)
    {
        // do not sell to the system user if enough match attempts has NOT made
        if ($sellOrder->getNumMatchAttempts() < $this->buyOrderMatchThreshold) {
            $this->logger->debug("Sell order [{$sellOrder->getId()}] has not reached the threshold [{$this->buyOrderMatchThreshold}] to be settled via an issuer");

            return self::SKIPPED;
        }

        $asset = $this->ultraAssetsRepository->getAssetById($sellOrder->getAssetId());
        if (is_null($asset)) {
            $this->logger->warning("A valid ULTRA asset cannot be found for the asset id {$sellOrder->getAssetId()}", [__CLASS__]);

            return self::SKIPPED;
        }

        $refusal = $this->buyBackPolicy->refusalReason($asset);
        if ($refusal !== null) {
            // stays on the order book for a member to buy
            $this->logger->info(sprintf('Sell order [%d] is not bought back: %s', $sellOrder->getId(), $refusal), [__CLASS__]);

            return self::SKIPPED;
        }

        $sellAssetAmount = round($sellOrder->getAmount() - $sellOrder->getSettledAmountSoFar(), 4);
        if ($sellAssetAmount <= 0) {
            return self::SKIPPED;
        }

        // asset balance validation : do not let anyone sell more than the available number of assets in their wallet
        $sellerWallet = $this->walletRepository->getUserWallet($sellOrder->getUserId(), $sellOrder->getAssetId());
        if (is_null($sellerWallet)) {
            return self::SKIPPED;
        }
        if (($sellerWallet->getAvailableBalance() - $sellAssetAmount) < 0) {
            $rejectionReason = sprintf(
                'There is no enough balance in the seller\'s wallet, \'%s\' to sell %s. Only %s available now.',
                $sellerWallet->getPublicKey(),
                $sellAssetAmount,
                $sellerWallet->getAvailableBalance()
            );
            $this->logger->debug($rejectionReason, [__CLASS__]);
            $this->orderRepository->rejectOrder($sellOrder->getId(), $rejectionReason);

            return self::SKIPPED;
        }

        $venAmountForOneAsset = $this->getVenAmountForOneAsset($asset);
        $totalVenAmountForAssets = VenLedger::toCents($venAmountForOneAsset * $sellAssetAmount);
        if ($totalVenAmountForAssets <= 0) {
            $this->logger->warning(sprintf('Sell order [%d] is worth less than one cent of Ven; skipping', $sellOrder->getId()), [__CLASS__]);

            return self::SKIPPED;
        }
        $systemWallet = $this->walletRepository->getUserWallet(MatchEngine::SYSTEM_USER_ID, $asset->id());
        if (is_null($systemWallet)) {
            $this->logger->error("Sell order [{$sellOrder->getId()}]: the system account's wallet cannot be opened; skipping", [__CLASS__]);

            return self::SKIPPED;
        }
        $metaData = $this->metaData($asset, $venAmountForOneAsset, $totalVenAmountForAssets);

        // ---- claim: nothing below runs twice for this order
        $guard = $this->guard();
        $token = $guard->newToken();
        $key = SettlementGuard::orderKey($sellOrder->getId());
        if (!$this->claim($guard, $sellOrder->getId(), $key, 'system-buy-back', $token)) {
            return self::SKIPPED;
        }

        // ---- the seller's assets first (the debit re-checks the balance under a row lock), then the Ven
        try {
            $this->walletRepository->debit($sellerWallet, $sellAssetAmount, $metaData);
        } catch (WalletException | InsufficientBalanceException $e) {
            $guard->failSettlement($key, $token, 'Units not debited: ' . $e->getMessage());
            $guard->rejectOrder($sellOrder->getId(), $token, $e->getMessage());

            return self::SKIPPED;
        } catch (\Exception $e) {
            return $this->review($guard, $sellOrder->getId(), $key, $token, 'the seller debit has an unknown outcome: ' . $e->getMessage(), []);
        }
        $guard->recordProgress($key, $token, ['units_debited' => $sellAssetAmount]);

        $venMessage = "Sold an amount of {$sellAssetAmount} {$asset->title()} assets @{$venAmountForOneAsset} VEN per 1 {$asset->title()}. Click <a href='/markets/my-wallets/transactions?id={$sellerWallet->getId()}'>here</a> for more info.";
        try {
            $this->venRepository->sendVen(MatchEngine::SYSTEM_USER_ID, $sellOrder->getUserId(), $totalVenAmountForAssets, $venMessage);
        } catch (\Exception $e) {
            if ($this->isUncertain($e)) {
                return $this->review($guard, $sellOrder->getId(), $key, $token, 'the Ven payout has an unknown outcome after the units were debited: ' . $e->getMessage(), []);
            }
            // nothing was paid: give the units back, and the order stays pending for the next run
            try {
                $this->walletRepository->credit($sellerWallet, $sellAssetAmount, $metaData);
            } catch (\Exception $creditError) {
                return $this->review($guard, $sellOrder->getId(), $key, $token, 'the Ven payout failed and the units could not be returned: ' . $creditError->getMessage(), []);
            }
            $this->logger->error(sprintf('Sell order [%d]: Ven payout failed, assets returned: %s', $sellOrder->getId(), $e->getMessage()), [__CLASS__]);
            $guard->failSettlement($key, $token, 'Ven payout failed; units returned: ' . $e->getMessage());
            $guard->releaseOrder($sellOrder->getId(), $token);

            return self::SKIPPED;
        }

        // the system user now holds what it bought
        try {
            $this->walletRepository->credit($systemWallet, $sellAssetAmount, $metaData);
        } catch (\Exception $e) {
            return $this->review($guard, $sellOrder->getId(), $key, $token, 'the seller was paid but the system wallet could not be credited with the units: ' . $e->getMessage(), []);
        }

        $guard->completeSettlement($key, $token, sprintf('%s units for %s Ven', $sellAssetAmount, $totalVenAmountForAssets));
        $guard->finishOrder($sellOrder->getId(), $token);
        $this->logger->info(sprintf("Done processing this sell order [%d]", $sellOrder->getId()), [__CLASS__]);

        return self::DONE;
    }

    /**
     * Claims the order row, then its settlement key. Only true means "go ahead and move money".
     *
     * @param SettlementGuard $guard
     * @param int             $orderId
     * @param string          $key
     * @param string          $kind
     * @param string          $token
     *
     * @return bool
     */
    private function claim(SettlementGuard $guard, $orderId, $key, $kind, $token)
    {
        if (!$guard->claimOrder($orderId, $token)) {
            $this->logger->info("Order [{$orderId}] is no longer pending (another run, or it changed); skipping", [__CLASS__]);

            return false;
        }

        switch ($guard->beginSettlement($key, $kind, $orderId, null, $token)) {
            case SettlementGuard::CLAIMED:
                return true;
            case SettlementGuard::ALREADY_COMPLETED:
                // an earlier run moved the money and stopped before marking the order: finish it, don't pay again
                $guard->finishOrder($orderId, $token, 'Completed after an interrupted match engine run');
                $this->logger->warning("Order [{$orderId}] was already settled; marked processed without paying again", [__CLASS__]);

                return false;
            case SettlementGuard::BLOCKED:
                $guard->flagOrder($orderId, $token, "settlement {$key} is already claimed or in review");

                return false;
            default:
                $guard->releaseOrder($orderId, $token);

                return false;
        }
    }

    /**
     * @param SettlementGuard $guard
     * @param int             $orderId
     * @param string          $key
     * @param string          $token
     * @param string          $why
     * @param array           $paid the Ven legs that moved
     *
     * @return string STOP
     */
    private function review(SettlementGuard $guard, $orderId, $key, $token, $why, array $paid)
    {
        if (!empty($paid)) {
            $guard->recordProgress($key, $token, ['ven_paid' => $paid]);
        }
        $guard->reviewSettlement($key, $token, $why);
        $guard->flagOrder($orderId, $token, $why);

        return self::STOP;
    }

    /**
     * Gives the buyer back the Ven already paid to issuers.
     *
     * @param BuyOrder $buyOrder
     * @param array    $paid
     *
     * @return bool true when every refund went through
     */
    private function refundIssuers(BuyOrder $buyOrder, array $paid)
    {
        $allRefunded = true;
        foreach ($paid as $payment) {
            try {
                $this->venRepository->sendVen($payment['issuer'], $buyOrder->getUserId(), $payment['ven'], "Refund: Ultra buy order {$buyOrder->getId()} could not be completed");
            } catch (\Exception $refundError) {
                $allRefunded = false;
                $this->logger->critical(sprintf('Refund of %s Ven from %d to %d for buy order [%d] failed: %s', $payment['ven'], $payment['issuer'], $buyOrder->getUserId(), $buyOrder->getId(), $refundError->getMessage()), [__CLASS__]);
            }
        }

        return $allRefunded;
    }

    /**
     * @param \Exception $e thrown by sendVen
     *
     * @return bool true unless it is known that no Ven moved
     */
    private function isUncertain(\Exception $e)
    {
        return !($e instanceof VenTransferException) || $e->isUncertain();
    }

    /**
     * @return SettlementGuard
     */
    private function guard()
    {
        if ($this->guard === null) {
            $this->guard = new SettlementGuard($this->orderRepository->getConnection(), $this->logger);
        }

        return $this->guard;
    }

    /**
     * @param UltraAsset $asset
     * @param float      $venAmountForOneAsset
     * @param float      $totalVen
     *
     * @return array
     */
    private function metaData(UltraAsset $asset, $venAmountForOneAsset, $totalVen)
    {
        $weightingConfig = [];
        $weightings = $asset->weightings();
        array_walk($weightings, function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
            $weightingConfig[] = $weighting->toArray();
        });
        $metaData = [];
        $metaData[MatchedOrderMetaData::ASSET_AMOUNT_IN_VEN] = $totalVen;
        $metaData['asset_amount_for_one_ven'] = $this->exchange
            ->convertFromVenToOther(new Money(1, Currency::VEN()), $asset->getCurrency())
            ->getAmountAsString();
        $metaData[MatchedOrderMetaData::VEN_AMOUNT_FOR_ONE_ASSET] = $venAmountForOneAsset;
        $metaData['weightingConfig'] = $weightingConfig;

        return $metaData;
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
        // the same price DefaultWalletHandler quotes and checks at the purchase
        if ($asset->weightingType() == UltraAssetsRepository::TYPE_VEN_AMOUNT && !empty($asset->getCustomVenAmount())) {
            return floatval($asset->getCustomVenAmount());
        }

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
