<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\Ven\UltraVenRepository;
use Hub\UltraCore\Wallet\WalletRepository;
use Psr\Log\LoggerInterface;

class MachineEngine
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
     * @param LoggerInterface     $logger
     */
    public function __construct(
        TradingOrderMatcher $tradingOrderMatcher,
        OrderRepository $orderRepository,
        WalletRepository $walletRepository,
        UltraVenRepository $venRepository,
        LoggerInterface $logger
    ) {
        $this->tradingOrderMatcher = $tradingOrderMatcher;
        $this->orderRepository = $orderRepository;
        $this->walletRepository = $walletRepository;
        $this->venRepository = $venRepository;
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

        foreach ($settledOrderPairs as $orderPair) {
            $buyOrder = $orderPair->getBuyOrder();
            $sellOrder = $orderPair->getSellOrder();

            $buyerAssetWallet = $this->walletRepository->getUserWallet(
                $buyOrder->getBuyerUserId(),
                $buyOrder->getAssetId()
            );
            $sellerAssetWallet = $this->walletRepository->getUserWallet(
                $sellOrder->getSellerUserId(),
                $sellOrder->getAssetId()
            );

            // let's settle the order in terms of assets. (debit and credit the two user accounts respectively)
            $metaData = [
                'ven_amount_for_one_asset' => $buyOrder->getOfferingRate(),
                'asset_amount_in_ven' => $buyOrder->getOfferingRate() * $orderPair->getSettledAmount(),
                'commit_outcome' => 'processed',
            ];
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
                $buyOrder->getBuyerUserId(),
                $sellOrder->getSellerUserId(),
                $sellerTotalGainInVen,
                'An order placed on the ULTRA exchange'
            );

            /**
             * Each time we have a match means that,
             * we have found a buyer who is willing to buy paying more than the seller's offered rate.
             *
             * This means there is a profit under the carpet that we/system/hub culture can keep.
             * And there is no harm here.
             */
            $sellerProfitInVen = $buyerTotalPayInVen - ($sellerOfferingRateInVen * $orderPair->getSettledAmount());
            if ($sellerProfitInVen > 0) {
                $this->venRepository->sendVen(
                    $sellOrder->getSellerUserId(),
                    self::SYSTEM_USER_ID,
                    $sellerProfitInVen,
                    sprintf(
                        'Profit made from an order placed in the exchange. buy order: %d, sell order: %d',
                        $buyOrder->getId(),
                        $sellOrder->getId()
                    ),
                    true
                );
            }
        }
    }
}