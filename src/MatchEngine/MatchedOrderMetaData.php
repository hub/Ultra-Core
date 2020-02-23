<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\MatchEngine\Order\MatchedOrderPair;

class MatchedOrderMetaData
{
    const VEN_AMOUNT_FOR_ONE_ASSET = 'ven_amount_for_one_asset';
    const ASSET_AMOUNT_IN_VEN = 'asset_amount_in_ven';

    /**
     * @param MatchedOrderPair $matchedOrderPair
     *
     * @return array
     */
    public static function from(MatchedOrderPair $matchedOrderPair)
    {
        $buyOrder = $matchedOrderPair->getBuyOrder();
        $sellOrder = $matchedOrderPair->getSellOrder();

        return [
            'buy_order_id' => $buyOrder->getId(),
            'sell_order_od' => $sellOrder->getId(),
            'buyer_ven_amount_for_one_asset' => $buyOrder->getOfferingRate(),
            'seller_ven_amount_for_one_asset' => $sellOrder->getOfferingRate(),
            /**
             * Needed by the frontend
             * @see https://hubculture.com/markets/my-wallets/index
             */
            self::VEN_AMOUNT_FOR_ONE_ASSET => $buyOrder->getOfferingRate(),
            self::ASSET_AMOUNT_IN_VEN => $buyOrder->getOfferingRate() * $matchedOrderPair->getSettledAmount(),
        ];
    }
}
