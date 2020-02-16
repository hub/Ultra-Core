<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine\Order;

class Orders
{
    const STATUS_PENDING = 'pending';
    const STATUS_REJECTED = 'rejected';
    const STATUS_PROCESSED = 'processed';
    const TYPE_SELL = 'sell';
    const TYPE_PURCHASE = 'buy';

    /**
     * @var BuyOrder[]
     */
    private $buyOrders = [];

    /**
     * @var SellOrder[]
     */
    private $sellOrders = [];

    /**
     * Orders constructor.
     *
     * @param BuyOrder[]  $buyOrders  List of all unsettled buy orders
     * @param SellOrder[] $sellOrders List of all unsettled sell orders
     */
    public function __construct(array $buyOrders, array $sellOrders)
    {
        foreach ($buyOrders as $buyOrder) {
            if ($buyOrder instanceof BuyOrder) {
                $this->buyOrders[] = $buyOrder;
            }
        }
        foreach ($sellOrders as $sellOrder) {
            if ($sellOrder instanceof SellOrder) {
                $this->sellOrders[] = $sellOrder;
            }
        }
    }

    /**
     * @return BuyOrder[]
     */
    public function getBuyOrders()
    {
        return $this->buyOrders;
    }

    /**
     * @return SellOrder[]
     */
    public function getSellOrders()
    {
        return $this->sellOrders;
    }
}
