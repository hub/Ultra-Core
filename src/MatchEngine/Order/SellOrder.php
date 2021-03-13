<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 */

namespace Hub\UltraCore\MatchEngine\Order;

class SellOrder extends AbstractOrder
{
    /**
     * @param AbstractOrder $buyOrder
     *
     * @return self
     */
    public function match(AbstractOrder $buyOrder)
    {
        if ($this->hasSettled()
            // never match orders with different assets. It is like selling YEN to get USD
            // while we are trying to match within the same currency/asset
            || $this->getAssetId() !== $buyOrder->getAssetId()

            // never match the buy and sell orders from the same user account
            || $this->getUserId() === $buyOrder->getUserId()

            // NOT A MATCH, if this buyer has nothing left to be bought
            || $buyOrder->getAmount() - $buyOrder->getSettledAmountSoFar() <= 0.0
        ) {
            return null;
        }

        // A match if the buyer is buying for more than the seller's offering rate.
        if ($this->getOfferingRate() < $buyOrder->getOfferingRate()) {
            return $this;
        }

        return null;
    }

    /**
     * Use this to get a pending sell order.
     *
     * @param int   $userId       Unique Hub Culture user identifier related to this sell order.
     * @param int   $assetId      A valid asset identifier
     * @param float $offeringRate The rate at which the seller is willing to sell the given asset for a buyer.
     * @param float $amount       Amount of assets being sold originally. Aka number of units.
     *
     * @return SellOrder
     */
    public static function newPendingOrder($userId, $assetId, $offeringRate, $amount)
    {
        return new self(
            0,
            $userId,
            $assetId,
            $offeringRate,
            $amount,
            0,
            Orders::STATUS_PENDING,
            0
        );
    }
}
