<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\MatchEngine\Order\MatchedOrderPair;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\Ven\UltraVenRepository;
use Hub\UltraCore\Wallet\WalletRepository;
use Psr\Log\LoggerInterface;

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
     * MachineEngine constructor.
     *
     * @param TradingOrderMatcher $tradingOrderMatcher
     * @param OrderRepository     $orderRepository
     * @param WalletRepository    $walletRepository
     * @param UltraVenRepository  $venRepository
     * @param BuyerIssuerMatcher  $buyerIssuerMatcher
     * @param LoggerInterface     $logger
     */
    public function __construct(
        TradingOrderMatcher $tradingOrderMatcher,
        OrderRepository $orderRepository,
        WalletRepository $walletRepository,
        UltraVenRepository $venRepository,
        BuyerIssuerMatcher $buyerIssuerMatcher,
        LoggerInterface $logger
    ) {
        $this->tradingOrderMatcher = $tradingOrderMatcher;
        $this->orderRepository = $orderRepository;
        $this->walletRepository = $walletRepository;
        $this->venRepository = $venRepository;
        $this->buyerIssuerMatcher = $buyerIssuerMatcher;
        $this->logger = $logger;
    }

    public function execute()
    {
        $lastSettlementId = $this->orderRepository->lastSettlementId();
        $this->logger->debug("Last settled order pair id is : {$lastSettlementId}.", [__CLASS__]);

        // match the orders and save the matched order pairs
        $this->tradingOrderMatcher->match();

        $settledOrderPairs = $this->orderRepository->getSettledOrderPairsLoggedAfterId($lastSettlementId);
        $this->logger->debug(sprintf("Found '%d' new settled order pairs.", count($settledOrderPairs)), [__CLASS__]);

        $this->settleMatchedOrders($settledOrderPairs);

        /**
         * Time to settle buy orders which we couldn't process/settle solely by matching sell orders.
         * This is the requirement that I received from product owner Stan.
         */
        $this->buyerIssuerMatcher->execute();
    }

    /**
     * @param MatchedOrderPair[] $settledOrderPairs
     */
    private function settleMatchedOrders(array $settledOrderPairs)
    {
        foreach ($settledOrderPairs as $orderPair) {
            $buyOrder = $orderPair->getBuyOrder();
            $sellOrder = $orderPair->getSellOrder();

            $buyerAssetWallet = $this->walletRepository->getUserWallet(
                $buyOrder->getUserId(),
                $buyOrder->getAssetId()
            );
            $sellerAssetWallet = $this->walletRepository->getUserWallet(
                $sellOrder->getUserId(),
                $sellOrder->getAssetId()
            );

            // let's settle the order in terms of assets. (debit and credit the two user accounts respectively)
            $metaData = MatchedOrderMetaData::from($orderPair);
            $this->walletRepository->credit($buyerAssetWallet, $orderPair->getSettledAmount(), $metaData);
            $this->walletRepository->debit($sellerAssetWallet, $orderPair->getSettledAmount(), $metaData);

            /**
             * Let's settle the Ven amounts
             */
            // buyer is willing to buy 1 asset per this much Ven. ex: 1 uUSD = 9.9309 Ven
            $buyerOfferingRateInVen = $buyOrder->getOfferingRate();
            $buyerTotalPayInVen = $buyerOfferingRateInVen * $orderPair->getSettledAmount();

            // while the seller is willing to sell one asset per this much Ven and the rate is always higher
            $sellerOfferingRateInVen = $sellOrder->getOfferingRate();
            $sellerTotalGainInVen = $buyerTotalPayInVen; // seller get the same Ven that buyer paid for it

            // let's send ven from the buyer to the seller as we settle this order in terms of Ven
            $this->venRepository->sendVen(
                $buyOrder->getUserId(),
                $sellOrder->getUserId(),
                $sellerTotalGainInVen,
                'An order placed on the ULTRA Exchange'
            );

            /**
             * Each time we have a match means that,
             * we have found a buyer who is willing to buy paying more than the seller's offered rate.
             *
             * This means there is a profit under the carpet that we/system/hub culture can keep.
             */
            $sellerProfitInVen = $buyerTotalPayInVen - ($sellerOfferingRateInVen * $orderPair->getSettledAmount());
            if ($sellerProfitInVen > 0) {
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
            }
        }
    }
}
