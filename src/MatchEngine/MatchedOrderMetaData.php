<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@8x8.com>
 * @copyright (c) 2020 by 8x8 Inc.
 *  _____      _____
 * |  _  |    |  _  |
 *  \ V /__  __\ V /   ___ ___  _ __ ___
 *  / _ \\ \/ // _ \  / __/ _ \| '_ ` _ \
 * | |_| |>  <| |_| || (_| (_) | | | | | |
 * \_____/_/\_\_____(_)___\___/|_| |_| |_|
 * All rights reserved.
 *
 * This software is the confidential and proprietary information
 * of 8x8 Inc. ("Confidential Information").  You
 * shall not disclose such Confidential Information and shall use
 * it only in accordance with the terms of the license agreement
 * you entered into with 8x8 Inc.
 *
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
