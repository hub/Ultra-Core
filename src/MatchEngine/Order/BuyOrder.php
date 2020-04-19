<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine\Order;

class BuyOrder extends AbstractOrder
{
    /**
     * @param AbstractOrder $sellOrder
     *
     * @return self
     */
    public function match(AbstractOrder $sellOrder)
    {
        if ($this->hasSettled()
            // never match orders with different assets. It is like selling YEN to get USD
            // while we are trying to match within the same currency/asset
            || $this->getAssetId() !== $sellOrder->getAssetId()

            // never match the buy and sell orders from the same user account
            || $this->getUserId() === $sellOrder->getUserId()

            // NOT A MATCH, if this seller has nothing left to sell
            || $sellOrder->getAmount() - $sellOrder->getSettledAmountSoFar() <= 0.0
        ) {
            return null;
        }

        // A match if the seller is offering for less than the rate, the buyer is willing to buy
        if ($this->getOfferingRate() > $sellOrder->getOfferingRate()) {
            return $this;
        }

        return null;
    }

    /**
     * Use this to get a pending buy order.
     *
     * @param int   $userId       Unique Hub Culture user identifier related to this buy order.
     * @param int   $assetId      A valid asset identifier
     * @param float $offeringRate The rate at which the buyer is willing to buy the given asset from a seller.
     * @param float $amount       Amount of assets being bought originally. Aka number of units.
     *
     * @return BuyOrder
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
