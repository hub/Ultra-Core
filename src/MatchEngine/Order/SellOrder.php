<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
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
}
