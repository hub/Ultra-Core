<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 */

namespace Hub\UltraCore\MatchEngine\Order;

class MatchedOrderPair
{
    /**
     * @var BuyOrder
     */
    private $buyOrder;

    /**
     * @var SellOrder
     */
    private $sellOrder;

    /**
     * @var float Amount of assets settled for this matched pair
     */
    private $settledAmount;

    /**
     * @var int|null
     */
    private $settlementId;

    /**
     * MatchedOrderPair constructor.
     *
     * @param BuyOrder  $buyOrder      The matched buy order
     * @param SellOrder $sellOrder     The matched sell order
     * @param float     $settledAmount Amount of assets settled for this matched pair
     * @param int|null  $settlementId  ultra_custom_buy_sell_order_settlements.id: the pair's settlement key
     */
    public function __construct(BuyOrder $buyOrder, SellOrder $sellOrder, $settledAmount, $settlementId = null)
    {
        $this->buyOrder = $buyOrder;
        $this->sellOrder = $sellOrder;
        $this->settledAmount = floatval($settledAmount);
        $this->settlementId = $settlementId === null ? null : (int)$settlementId;
    }

    /**
     * @return int|null ultra_custom_buy_sell_order_settlements.id, when the pair was read from it
     */
    public function getSettlementId()
    {
        return $this->settlementId;
    }

    /**
     * @return BuyOrder
     */
    public function getBuyOrder()
    {
        return $this->buyOrder;
    }

    /**
     * @return SellOrder
     */
    public function getSellOrder()
    {
        return $this->sellOrder;
    }

    /**
     * Returns the amount of assets settled for this matched pair.
     *
     * @return float Amount of assets settled
     */
    public function getSettledAmount()
    {
        return $this->settledAmount;
    }
}
