<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
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
     * @var float
     */
    private $settledAmount;

    /**
     * MatchedOrderPair constructor.
     *
     * @param BuyOrder  $buyOrder
     * @param SellOrder $sellOrder
     * @param float     $settledAmount
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
     * @return float
     */
    public function getSettledAmount()
    {
        return $this->settledAmount;
    }
}
