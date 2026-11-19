<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 */

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\MatchEngine\Order\AbstractOrder;
use Hub\UltraCore\MatchEngine\Order\BuyOrder;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\MatchEngine\Order\Orders;
use Hub\UltraCore\MatchEngine\Order\SellOrder;
use Hub\UltraCore\Wallet\WalletRepository;

class TradingOrderMatcher
{
    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var WalletRepository
     */
    private $walletRepository;

    /**
     * TradingOrderMatcher constructor.
     *
     * @param OrderRepository  $orderRepository
     * @param WalletRepository $walletRepository
     */
    public function __construct(OrderRepository $orderRepository, WalletRepository $walletRepository)
    {
        $this->orderRepository = $orderRepository;
        $this->walletRepository = $walletRepository;
    }

    /**
     * Use this to match sell and buy orders and to record the matched/settled pairs.
     *
     * @return Orders
     */
    public function match()
    {
        $orders = $this->orderRepository->getPendingOrders();
        $buyOrders = $orders->getBuyOrders();
        $sellOrders = $orders->getSellOrders();

        // search and match a buy order for each sell order
        foreach ($sellOrders as &$sellOrder) {
            $this->orderRepository->incrementMatchAttempts($sellOrder->getId());
            $previousSettledSoFar = $sellOrder->getSettledAmountSoFar();
            $matchedOrder = $this->findBuyOrderForOfferingRate($buyOrders, $sellOrder);
            if (!is_null($matchedOrder)) {
                $this->recordMatch($sellOrder, $matchedOrder, $previousSettledSoFar);
            }
            // let's update the status to processed, if we have settled the full amount
            if ($sellOrder->getAmount() === $sellOrder->getSettledAmountSoFar()) {
                $sellOrder->setStatus(Orders::STATUS_PROCESSED);
                $this->orderRepository->processOrder($sellOrder->getId());
            }
        }

        // search and match a sell order for each buy order
        foreach ($buyOrders as &$buyOrder) {
            $this->orderRepository->incrementMatchAttempts($buyOrder->getId());
            $previousSettledSoFar = $buyOrder->getSettledAmountSoFar();
            $matchedOrder = $this->findSellOrderForOfferingRate($sellOrders, $buyOrder);
            if (!is_null($matchedOrder)) {
                $this->recordMatch($buyOrder, $matchedOrder, $previousSettledSoFar);
            }
            // let's update the status to processed, if we have settled the full amount
            if ($buyOrder->getAmount() === $buyOrder->getSettledAmountSoFar()) {
                $buyOrder->setStatus(Orders::STATUS_PROCESSED);
                $this->orderRepository->processOrder($buyOrder->getId());
            }
        }

        return new Orders($buyOrders, $sellOrders);
    }

    /**
     * Persists both orders' matched amounts first, then logs the settlement the match engine pays. If either amount
     * can't be saved (the order changed since it was read), the match is undone in memory and nothing is logged, so
     * no money moves for it. Before this, the matched amount lived only in memory and every run matched (and the
     * issuer path bought) the whole order again.
     *
     * @param AbstractOrder $mainOrder
     * @param AbstractOrder $matchedOrder
     * @param float         $previousSettledSoFar the main order's matched amount before this match
     */
    private function recordMatch(AbstractOrder $mainOrder, AbstractOrder $matchedOrder, $previousSettledSoFar)
    {
        $delta = $mainOrder->getSettledAmountSoFar() - $previousSettledSoFar;
        $matchedPrevious = $matchedOrder->getSettledAmountSoFar() - $delta;

        $mainSaved = $this->orderRepository->updateSettledAmount($mainOrder->getId(), $previousSettledSoFar, $mainOrder->getSettledAmountSoFar()) === true;
        $matchedSaved = $mainSaved
            && $this->orderRepository->updateSettledAmount($matchedOrder->getId(), $matchedPrevious, $matchedOrder->getSettledAmountSoFar()) === true;
        if (!$matchedSaved) {
            if ($mainSaved) {
                $this->orderRepository->updateSettledAmount($mainOrder->getId(), $mainOrder->getSettledAmountSoFar(), $previousSettledSoFar);
            }
            $mainOrder->setSettledAmountSoFar($previousSettledSoFar);
            $matchedOrder->setSettledAmountSoFar($matchedPrevious);

            return;
        }

        if ($matchedOrder->getAmount() === $matchedOrder->getSettledAmountSoFar()) {
            $matchedOrder->setStatus(Orders::STATUS_PROCESSED);
            $this->orderRepository->processOrder($matchedOrder->getId());
        }

        $this->orderRepository->addSettlement($mainOrder->getId(), $matchedOrder->getId(), $delta);
    }

    /**
     * @param BuyOrder[] $buyOrders
     * @param SellOrder  $sellOrder
     *
     * @return BuyOrder|null a matched buy order for a high price than the seller's offered rate.
     */
    private function findBuyOrderForOfferingRate(array &$buyOrders, SellOrder &$sellOrder)
    {
        foreach ($buyOrders as &$buyOrder) {
            if ($buyOrder->match($sellOrder)) {
                $remainingAssetsToBeSold = $sellOrder->getAmount() - $sellOrder->getSettledAmountSoFar();
                $assetsToBeBought = $buyOrder->getAmount() - $buyOrder->getSettledAmountSoFar();
                // if has more assets to be sold than the buy amount
                if ($remainingAssetsToBeSold > $assetsToBeBought) {
                    $buyOrder->setSettledAmountSoFar($buyOrder->getSettledAmountSoFar() + $assetsToBeBought);
                    $sellOrder->setSettledAmountSoFar($sellOrder->getSettledAmountSoFar() + $assetsToBeBought);
                } else {
                    $buyOrder->setSettledAmountSoFar($buyOrder->getSettledAmountSoFar() + $remainingAssetsToBeSold);
                    $sellOrder->setSettledAmountSoFar($sellOrder->getSettledAmountSoFar() + $remainingAssetsToBeSold);
                }
                return $buyOrder;
            }
        }

        return null;
    }

    /**
     * @param SellOrder[] $sellOrders
     * @param BuyOrder    $buyOrder
     *
     * @return SellOrder|null a matched buy order for a high price than the buyer's offered rate.
     */
    private function findSellOrderForOfferingRate(array &$sellOrders, BuyOrder &$buyOrder)
    {
        foreach ($sellOrders as &$sellOrder) {
            if ($sellOrder->match($buyOrder)) {
                $remainingAssetsToBeSold = $sellOrder->getAmount() - $sellOrder->getSettledAmountSoFar();
                $assetsToBeBought = $buyOrder->getAmount() - $buyOrder->getSettledAmountSoFar();
                // if has more assets to be sold than the buy amount
                if ($remainingAssetsToBeSold > $assetsToBeBought) {
                    $buyOrder->setSettledAmountSoFar($buyOrder->getSettledAmountSoFar() + $assetsToBeBought);
                    $sellOrder->setSettledAmountSoFar($sellOrder->getSettledAmountSoFar() + $assetsToBeBought);
                } else {
                    $buyOrder->setSettledAmountSoFar($buyOrder->getSettledAmountSoFar() + $remainingAssetsToBeSold);
                    $sellOrder->setSettledAmountSoFar($sellOrder->getSettledAmountSoFar() + $remainingAssetsToBeSold);
                }
                return $sellOrder;
            }
        }

        return null;
    }
}
