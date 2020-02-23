<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Hub\UltraCore\UltraAssetsRepository;
use InvalidArgumentException;
use PDO;
use Psr\Log\LoggerInterface;

class OrderRepository
{
    /**
     * @var Connection
     */
    private $database;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * OrdersProvider constructor.
     *
     * @param Connection      $database
     * @param LoggerInterface $logger
     */
    public function __construct(Connection $database, LoggerInterface $logger)
    {
        $this->database = $database;
        $this->logger = $logger;
    }

    /**
     * Use this to add a sell order into the system. Sell orders are processed asynchronously later and settled if a
     * matching buy order found or processed by a system admin directly.
     * @see SellOrder::match()
     *
     * @param SellOrder $sellOrder
     * @param float     $venAmountForOneAsset This is the market rate of Ven per one asset at the time of the selling.
     *                                        Ex: 9.5 Ven = 1 uUSD
     */
    public function addSellOrder(SellOrder $sellOrder, $venAmountForOneAsset)
    {
        try {
            $this->database->insert(
                'ultra_custom_buy_sell_orders',
                [
                    'user_id' => $sellOrder->getUserId(),
                    'asset_id' => $sellOrder->getAssetId(),
                    'type' => Orders::TYPE_SELL,
                    'status' => $sellOrder->getStatus(),
                    'transaction_currency' => UltraAssetsRepository::CURRENCY_CODE_VEN_LABEL,
                    'offering_rate' => $sellOrder->getOfferingRate(),
                    'market_rate' => $venAmountForOneAsset,
                    'asset_amount' => $sellOrder->getAmount(),
                    'settled_amount_so_far' => 0,
                    'offer_expire_date' => '0000-00-00 00:00:00',
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            );
        } catch (DBALException $e) {
            $this->logger->error(sprintf(
                'Error occurred when saving a new sell order [%s]. Error : %s', (string)$sellOrder,
                $e->getMessage()
            ));
        }
    }

    /**
     * Use this to add a buy order into the system. Buy orders are processed asynchronously later and settled if a
     * matching sell order found or processed by a system admin directly.
     * @see BuyOrder::match()
     *
     * @param BuyOrder $buyOrder
     * @param float    $venAmountForOneAsset  This is the market rate of Ven per one asset at the time of the purchase.
     *                                        Ex: 9.5 Ven = 1 uUSD
     */
    public function addBuyOrder(BuyOrder $buyOrder, $venAmountForOneAsset)
    {
        try {
            $this->database->insert(
                'ultra_custom_buy_sell_orders',
                [
                    'user_id' => $buyOrder->getUserId(),
                    'asset_id' => $buyOrder->getAssetId(),
                    'type' => Orders::TYPE_PURCHASE,
                    'status' => $buyOrder->getStatus(),
                    'transaction_currency' => UltraAssetsRepository::CURRENCY_CODE_VEN_LABEL,
                    'offering_rate' => $buyOrder->getOfferingRate(),
                    'market_rate' => $venAmountForOneAsset,
                    'asset_amount' => $buyOrder->getAmount(),
                    'settled_amount_so_far' => 0,
                    'offer_expire_date' => '0000-00-00 00:00:00',
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            );
        } catch (DBALException $e) {
            $this->logger->error(sprintf(
                'Error occurred when saving a new buy order [%s]. Error : %s', (string)$buyOrder,
                $e->getMessage()
            ));
        }
    }

    /**
     * @return Orders
     */
    public function getOrders()
    {
        try {
            $resultSet = $this->database->query("SELECT * FROM `ultra_custom_buy_sell_orders` WHERE `status` = 'pending'");
        } catch (DBALException $e) {
            $this->logger->error(
                sprintf('Error occurred when retrieving unsettled buy/sell orders. Error : %s', $e->getMessage())
            );
            return new Orders([], []);
        }

        $buyOrders = [];
        $sellOrders = [];
        while ($order = $resultSet->fetch(PDO::FETCH_ASSOC)) {
            try {
                if ($order['type'] === Orders::TYPE_PURCHASE) {
                    $buyOrders[] = new BuyOrder(
                        $order['id'],
                        $order['user_id'],
                        $order['asset_id'],
                        $order['offering_rate'],
                        $order['asset_amount'],
                        $order['settled_amount_so_far'],
                        $order['status'],
                        $order['num_match_attempts']
                    );
                } elseif ($order['type'] === Orders::TYPE_SELL) {
                    $sellOrders[] = new SellOrder(
                        $order['id'],
                        $order['user_id'],
                        $order['asset_id'],
                        $order['offering_rate'],
                        $order['asset_amount'],
                        $order['settled_amount_so_far'],
                        $order['status'],
                        $order['num_match_attempts']
                    );
                }
            } catch (InvalidArgumentException $ex) {
                continue;
            }
        }

        return new Orders($buyOrders, $sellOrders);
    }

    /**
     * Use this to update the status of any order which has been settled
     *
     * @param Orders $orders
     *
     * @throws DBALException
     */
    public function updateOrders(Orders $orders)
    {
        foreach ($orders->getBuyOrders() as $buyOrder) {
            $this->database->update(
                'ultra_custom_buy_sell_orders',
                [
                    'status' => $buyOrder->getStatus(),
                    'settled_amount_so_far' => $buyOrder->getSettledAmountSoFar(),
                ],
                ['id' => $buyOrder->getId()],
                ['settled_amount_so_far' => PDO::PARAM_INT, 'status' => PDO::PARAM_STR]
            );
        }
        foreach ($orders->getSellOrders() as $sellOrder) {
            $this->database->update(
                'ultra_custom_buy_sell_orders',
                [
                    'status' => $sellOrder->getStatus(),
                    'settled_amount_so_far' => $sellOrder->getSettledAmountSoFar(),
                ],
                ['id' => $sellOrder->getId()],
                ['settled_amount_so_far' => PDO::PARAM_INT, 'status' => PDO::PARAM_STR]
            );
        }
    }

    /**
     * Use this to increment the match attempt that you have  carried out just now
     *
     * @param int $orderId Order that you need to update
     */
    public function incrementMatchAttempts($orderId)
    {
        if (intval($orderId) === 0) {
            return;
        }

        try {
            $this->database->query("UPDATE `ultra_custom_buy_sell_orders` SET `num_match_attempts` = `num_match_attempts` + 1 WHERE `id` = {$orderId}");
        } catch (DBALException $e) {
            $this->logger->error(sprintf(
                'Error occurred when increment the match attempt for order [%d]. Error : %s', $orderId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Use this to reject an order with a reason
     *
     * @param int    $orderId Order that you need to reject
     * @param string $notes
     */
    public function rejectOrder($orderId, $notes = '')
    {
        if (intval($orderId) === 0) {
            return;
        }

        try {
            $this->database->update(
                'ultra_custom_buy_sell_orders',
                [
                    'status' => Orders::STATUS_REJECTED,
                    'notes' => $notes,
                ],
                ['id' => $orderId],
                ['status' => PDO::PARAM_STR, 'notes' => PDO::PARAM_STR]
            );
        } catch (DBALException $e) {
            $this->logger->error(sprintf(
                'Error occurred when increment the match attempt for order [%d]. Error : %s', $orderId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Use this to record a settlement for a order pair.
     *
     * @param int   $mainOrderId    Main order id which we are settling
     * @param int   $matchedOrderId The matched order id
     * @param float $settleAmount   The amount being settled for the given main order id
     *
     * @throws DBALException
     */
    public function addSettlement($mainOrderId, $matchedOrderId, $settleAmount)
    {
        $this->database->insert(
            'ultra_custom_buy_sell_order_settlements',
            [
                'order_id' => $mainOrderId,
                'matched_order_id' => $matchedOrderId,
                'asset_amount' => $settleAmount,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            ['order_id' => PDO::PARAM_INT, 'matched_order_id' => PDO::PARAM_INT, 'asset_amount' => PDO::PARAM_INT]
        );
    }

    /**
     * This returns the last inserted settlement id
     *
     * @return int|null last inserted settlement id or null
     */
    public function lastSettlementId()
    {
        try {
            $resultSet = $this->database->query('SELECT MAX(id) AS `id` FROM `ultra_custom_buy_sell_order_settlements`');
        } catch (DBALException $e) {
            $this->logger->error(sprintf(
                'Error occurred when querying the last settlement data. Error : %s', $e->getMessage()
            ));
            return null;
        }

        return intval($resultSet->fetchColumn(0));
    }

    /**
     * This returns all the settled order pairs added after a given settlement id.
     *
     * @param int $lastSettlementId The last settlement id that you need to use to get the latest ones prior to this.
     *
     * @return MatchedOrderPair[]
     */
    public function getSettledOrderPairsLoggedAfterId($lastSettlementId)
    {
        try {
            $resultSet = $this->database->query(<<<SQL
SELECT
    o.id AS `main_order_id`,
    o.user_id AS `main_order_user_id`,
    o.asset_id AS `main_order_asset_id`,
    o.type AS `main_order_type`,
    o.offering_rate AS `main_order_offering_rate`,
    o.asset_amount AS `main_order_asset_amount`,
    o.settled_amount_so_far AS `main_order_settled_amount_so_far`,
    o.status AS `main_order_status`,
    o.num_match_attempts AS `main_order_num_match_attempts`,
    o2.id AS `matched_order_id`,
    o2.user_id AS `matched_order_user_id`,
    o2.asset_id AS `matched_order_asset_id`,
    o2.type AS `matched_order_type`,
    o2.offering_rate AS `matched_order_offering_rate`,
    o2.asset_amount AS `matched_order_asset_amount`,
    o2.settled_amount_so_far AS `matched_order_settled_amount_so_far`,
    o2.status AS `matched_order_status`,
    o2.num_match_attempts AS `matched_order_num_match_attempts`,
    s.asset_amount AS `settled_amount`
FROM `ultra_custom_buy_sell_order_settlements` s
INNER JOIN ultra_custom_buy_sell_orders o ON (o.id = s.order_id)
INNER JOIN ultra_custom_buy_sell_orders o2 ON (o2.id = s.matched_order_id)
WHERE s.id > {$lastSettlementId}
SQL
            );
        } catch (DBALException $e) {
            $this->logger->error(sprintf(
                'Error occurred when querying the last settlement data. Error : %s', $e->getMessage()
            ));
            return [];
        }

        $settleOrderPairs = [];
        while ($row = $resultSet->fetch(PDO::FETCH_ASSOC)) {
            $buyOrder = null;
            $sellOrder = null;
            if ($row['main_order_type'] === Orders::TYPE_PURCHASE) {
                $buyOrder = new BuyOrder(
                    $row['main_order_id'],
                    $row['main_order_user_id'],
                    $row['main_order_asset_id'],
                    $row['main_order_offering_rate'],
                    $row['main_order_asset_amount'],
                    $row['main_order_settled_amount_so_far'],
                    $row['main_order_status'],
                    $row['main_order_num_match_attempts']
                );
            } elseif ($row['main_order_type'] === Orders::TYPE_SELL) {
                $sellOrder = new SellOrder(
                    $row['main_order_id'],
                    $row['main_order_user_id'],
                    $row['main_order_asset_id'],
                    $row['main_order_offering_rate'],
                    $row['main_order_asset_amount'],
                    $row['main_order_settled_amount_so_far'],
                    $row['main_order_status'],
                    $row['main_order_num_match_attempts']
                );
            }
            if ($row['matched_order_type'] === Orders::TYPE_PURCHASE) {
                $buyOrder = new BuyOrder(
                    $row['matched_order_id'],
                    $row['matched_order_user_id'],
                    $row['matched_order_asset_id'],
                    $row['matched_order_offering_rate'],
                    $row['matched_order_asset_amount'],
                    $row['matched_order_settled_amount_so_far'],
                    $row['matched_order_status'],
                    $row['matched_order_num_match_attempts']
                );
            } elseif ($row['matched_order_type'] === Orders::TYPE_SELL) {
                $sellOrder = new SellOrder(
                    $row['matched_order_id'],
                    $row['matched_order_user_id'],
                    $row['matched_order_asset_id'],
                    $row['matched_order_offering_rate'],
                    $row['matched_order_asset_amount'],
                    $row['matched_order_settled_amount_so_far'],
                    $row['matched_order_status'],
                    $row['matched_order_num_match_attempts']
                );
            }

            if (!is_null($buyOrder) && !is_null($sellOrder)) {
                $settleOrderPairs[] = new MatchedOrderPair($buyOrder, $sellOrder, $row['settled_amount']);
            }
        }

        return $settleOrderPairs;
    }
}
