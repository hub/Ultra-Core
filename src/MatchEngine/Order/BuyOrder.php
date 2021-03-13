<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
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
}
