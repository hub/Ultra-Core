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
     * MatchedOrderPair constructor.
     *
     * @param BuyOrder  $buyOrder      The matched buy order
     * @param SellOrder $sellOrder     The matched sell order
     * @param float     $settledAmount Amount of assets settled for this matched pair
     */
    public function __construct(BuyOrder $buyOrder, SellOrder $sellOrder, $settledAmount)
    {
        $this->buyOrder = $buyOrder;
        $this->sellOrder = $sellOrder;
        $this->settledAmount = floatval($settledAmount);
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
