<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 */

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\Exception\InsufficientBalanceException;
use Hub\UltraCore\Exception\VenTransferException;
use Hub\UltraCore\Exception\WalletException;
use Hub\UltraCore\MatchEngine\Order\MatchedOrderPair;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\MatchEngine\Settlement\MysqlNamedLock;
use Hub\UltraCore\MatchEngine\Settlement\RunLock;
use Hub\UltraCore\MatchEngine\Settlement\SettlementGuard;
use Hub\UltraCore\Ven\UltraVenRepository;
use Hub\UltraCore\Ven\VenLedger;
use Hub\UltraCore\Wallet\WalletRepository;
use Psr\Log\LoggerInterface;

/**
 * One match engine run:
 *   1. take the run lock (GET_LOCK); if another run holds it, do nothing;
 *   2. resolve orders a crashed run left claimed (SettlementGuard::recoverStaleClaims);
 *   3. match the order book and settle each new pair once, under its `pair:{settlement id}` key;
 *   4. settle what is left from issuers / the system buy-back (BuyerIssuerMatcher).
 *
 * Default to safety: when the state of the money is uncertain the settlement is flagged for review and the run
 * stops. Nothing is retried automatically.
 */
class MatchEngine
{
    const SYSTEM_USER_ID = 1;

    /**
     * @var TradingOrderMatcher
     */
    private $tradingOrderMatcher;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var WalletRepository
     */
    private $walletRepository;

    /**
     * @var UltraVenRepository
     */
    private $venRepository;

    /**
     * @var BuyerIssuerMatcher
     */
    private $buyerIssuerMatcher;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var RunLock|null
     */
    private $runLock;

    /**
     * @var SettlementGuard|null
     */
    private $guard;

    /**
     * MachineEngine constructor.
     *
     * @param TradingOrderMatcher  $tradingOrderMatcher
     * @param OrderRepository      $orderRepository
     * @param WalletRepository     $walletRepository
     * @param UltraVenRepository   $venRepository
     * @param BuyerIssuerMatcher   $buyerIssuerMatcher
     * @param LoggerInterface      $logger
     * @param RunLock|null         $runLock default: a GET_LOCK on the order repository's connection
     * @param SettlementGuard|null $guard   default: on the order repository's connection
     */
    public function __construct(
        TradingOrderMatcher $tradingOrderMatcher,
        OrderRepository $orderRepository,
        WalletRepository $walletRepository,
        UltraVenRepository $venRepository,
        BuyerIssuerMatcher $buyerIssuerMatcher,
        LoggerInterface $logger,
        ?RunLock $runLock = null,
        ?SettlementGuard $guard = null
    ) {
        $this->tradingOrderMatcher = $tradingOrderMatcher;
        $this->orderRepository = $orderRepository;
        $this->walletRepository = $walletRepository;
        $this->venRepository = $venRepository;
        $this->buyerIssuerMatcher = $buyerIssuerMatcher;
        $this->logger = $logger;
        $this->runLock = $runLock;
        $this->guard = $guard;
    }

    /**
     * @return bool false when the run didn't start (another run holds the lock) or stopped for a review
     */
    public function execute()
    {
        $lock = $this->runLock ?: new MysqlNamedLock($this->orderRepository->getConnection());
        if (!$lock->acquire()) {
            $this->logger->warning('Another match engine run holds the lock (or it cannot be taken); nothing done.', [__CLASS__]);

            return false;
        }

        try {
            if (!$this->guard()->isReady()) {
                return false;
            }
            $flagged = $this->guard()->recoverStaleClaims();
            if (!empty($flagged)) {
                $this->logger->critical(sprintf('Orders flagged for review after an interrupted run: %s', implode(', ', $flagged)), [__CLASS__]);
            }

            $lastSettlementId = $this->orderRepository->lastSettlementId();
            if ($lastSettlementId === null) {
                // without it every historic settlement would look new: never guess
                $this->logger->error('The last settlement id cannot be read; nothing done.', [__CLASS__]);

                return false;
            }
            $this->logger->debug("Last settled order pair id is : {$lastSettlementId}.", [__CLASS__]);

            // match the orders and save the matched order pairs
            $this->tradingOrderMatcher->match();

            $settledOrderPairs = $this->orderRepository->getSettledOrderPairsLoggedAfterId($lastSettlementId);
            $this->logger->debug(sprintf("Found '%d' new settled order pairs.", count($settledOrderPairs)), [__CLASS__]);

            if (!$this->settleMatchedOrders($settledOrderPairs)) {
                return false;
            }

            /**
             * Time to settle buy orders which we couldn't process/settle solely by matching sell orders.
             * This is the requirement that I received from product owner Stan.
             */
            return $this->buyerIssuerMatcher->execute() !== false;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param MatchedOrderPair[] $settledOrderPairs
     *
     * @return bool false when the run must stop (a pair is in review)
     */
    private function settleMatchedOrders(array $settledOrderPairs)
    {
        $guard = $this->guard();
        foreach ($settledOrderPairs as $orderPair) {
            $buyOrder = $orderPair->getBuyOrder();
            $sellOrder = $orderPair->getSellOrder();
            if ($orderPair->getSettlementId() === null) {
                $this->logger->error(sprintf('Buy order [%d] / sell order [%d]: no settlement id, so it cannot be settled exactly once; skipped', $buyOrder->getId(), $sellOrder->getId()), [__CLASS__]);
                continue;
            }

            $key = SettlementGuard::pairKey($orderPair->getSettlementId());
            $token = $guard->newToken();
            $claim = $guard->beginSettlement($key, 'pair', $buyOrder->getId(), $sellOrder->getId(), $token);
            if ($claim === SettlementGuard::ERROR) {
                // the pair won't be picked up again (runs read settlements logged after their start): say so loudly
                $this->logger->critical(sprintf('Settlement %s (buy order %d, sell order %d) could not be claimed and was NOT paid; settle it by hand. Stopping.', $key, $buyOrder->getId(), $sellOrder->getId()), [__CLASS__]);

                return false;
            }
            if ($claim !== SettlementGuard::CLAIMED) {
                $this->logger->warning(sprintf('Settlement %s: %s; not paid again', $key, $claim), [__CLASS__]);
                continue;
            }

            $buyerAssetWallet = $this->walletRepository->getUserWallet($buyOrder->getUserId(), $buyOrder->getAssetId());
            $sellerAssetWallet = $this->walletRepository->getUserWallet($sellOrder->getUserId(), $sellOrder->getAssetId());
            if (is_null($buyerAssetWallet) || is_null($sellerAssetWallet)) {
                $guard->failSettlement($key, $token, 'A wallet cannot be opened; nothing moved');
                continue;
            }

            // buyer is willing to buy 1 asset per this much Ven. ex: 1 uUSD = 9.9309 Ven. Ven moves in cents.
            $buyerTotalPayInVen = VenLedger::toCents($buyOrder->getOfferingRate() * $orderPair->getSettledAmount());
            // seller get the same Ven that buyer paid for it; their ask is always lower, Ultra keeps the difference
            $sellerTotalGainInVen = $buyerTotalPayInVen;
            $sellerAskInVen = VenLedger::toCents($sellOrder->getOfferingRate() * $orderPair->getSettledAmount());
            $metaData = MatchedOrderMetaData::from($orderPair);
            if ($buyerTotalPayInVen <= 0) {
                $guard->failSettlement($key, $token, 'Worth less than one cent of Ven; nothing moved');
                continue;
            }

            // Ven first: sendVen takes it only if the buyer has it. Then the assets; if the seller no longer has them,
            // the Ven goes back. One failed pair never stops the others; an uncertain one stops the run.
            try {
                $this->venRepository->sendVen(
                    $buyOrder->getUserId(),
                    $sellOrder->getUserId(),
                    $sellerTotalGainInVen,
                    'An order placed on the ULTRA Exchange'
                );
            } catch (\Exception $e) {
                if ($this->isUncertain($e)) {
                    return $this->review($key, $token, 'the buyer payment has an unknown outcome: ' . $e->getMessage());
                }
                $this->logger->error(sprintf('Settlement of buy order [%d] / sell order [%d]: payment failed, nothing moved: %s', $buyOrder->getId(), $sellOrder->getId(), $e->getMessage()), [__CLASS__]);
                $guard->failSettlement($key, $token, 'Payment failed, nothing moved: ' . $e->getMessage());
                $this->orderRepository->rejectOrder($buyOrder->getId(), 'Payment failed: ' . $e->getMessage());
                continue;
            }
            $guard->recordProgress($key, $token, ['ven_paid' => $sellerTotalGainInVen]);

            try {
                $this->walletRepository->debit($sellerAssetWallet, $orderPair->getSettledAmount(), $metaData);
            } catch (WalletException | InsufficientBalanceException $e) {
                try {
                    $this->venRepository->sendVen($sellOrder->getUserId(), $buyOrder->getUserId(), $sellerTotalGainInVen, 'Refund: an ULTRA Exchange order could not be settled');
                } catch (\Exception $refundError) {
                    return $this->review($key, $token, 'the seller no longer has the assets and the buyer could not be refunded: ' . $refundError->getMessage());
                }
                $this->logger->error(sprintf('Settlement of sell order [%d]: the seller no longer has the assets; Ven refunded: %s', $sellOrder->getId(), $e->getMessage()), [__CLASS__]);
                $guard->failSettlement($key, $token, 'Seller lacked the assets; Ven refunded: ' . $e->getMessage());
                $this->orderRepository->rejectOrder($sellOrder->getId(), $e->getMessage());
                continue;
            } catch (\Exception $e) {
                return $this->review($key, $token, 'the seller debit has an unknown outcome after the Ven was paid: ' . $e->getMessage());
            }
            $guard->recordProgress($key, $token, ['ven_paid' => $sellerTotalGainInVen, 'units_debited' => $orderPair->getSettledAmount()]);

            try {
                $this->walletRepository->credit($buyerAssetWallet, $orderPair->getSettledAmount(), $metaData);
            } catch (\Exception $e) {
                return $this->review($key, $token, 'the buyer paid and the seller was debited, but the buyer could not be credited: ' . $e->getMessage());
            }

            $note = '';
            $sellerProfitInVen = VenLedger::toCents($buyerTotalPayInVen - $sellerAskInVen);
            if ($sellerProfitInVen > 0) {
                try {
                    $this->venRepository->sendVen(
                        $sellOrder->getUserId(),
                        self::SYSTEM_USER_ID,
                        $sellerProfitInVen,
                        sprintf(
                            'Profit made from an order placed in the exchange. buy order: %d, sell order: %d',
                            $buyOrder->getId(),
                            $sellOrder->getId()
                        ),
                        true
                    );
                    $this->logger->debug(
                        "Transferred '{$sellerProfitInVen} Ven' to the HubCulture system account as a profit.", [__CLASS__]
                    );
                } catch (\Exception $e) {
                    if ($this->isUncertain($e)) {
                        return $this->review($key, $token, 'settled, but the exchange fee transfer has an unknown outcome: ' . $e->getMessage());
                    }
                    $note = sprintf('exchange fee of %s Ven not collected: %s', $sellerProfitInVen, $e->getMessage());
                    $this->logger->error(sprintf('Exchange fee of %s Ven for sell order [%d] not collected: %s', $sellerProfitInVen, $sellOrder->getId(), $e->getMessage()), [__CLASS__]);
                }
            }

            $guard->completeSettlement($key, $token, $note);
        }

        return true;
    }

    /**
     * @param string $key
     * @param string $token
     * @param string $why
     *
     * @return bool false: stop the run
     */
    private function review($key, $token, $why)
    {
        $this->guard()->reviewSettlement($key, $token, $why);

        return false;
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
}
