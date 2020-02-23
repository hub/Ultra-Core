<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine;

use Doctrine\DBAL\Connection;
use Hub\UltraCore\MatchEngine\Order\BuyOrder;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\MatchEngine\Order\Orders;
use Hub\UltraCore\MatchEngine\Order\SellOrder;
use Hub\UltraCore\Wallet\WalletRepository;
use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_MockObject_MockObject;

class TradingOrderMatcherTest extends TestCase
{
    /**
     * @test
     * @dataProvider testOrderProvider
     *
     * @param Orders $actualUserPlacesOrders
     * @param Orders $expectedMatchedOrders
     */
    public function shouldMatchOrders(Orders $actualUserPlacesOrders, Orders $expectedMatchedOrders)
    {
        /** @var PHPUnit_Framework_MockObject_MockObject|Connection $connectionMock */
        $connectionMock = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->getMock();
        $connectionMock->method('query');
        /** @var PHPUnit_Framework_MockObject_MockObject|WalletRepository $walletRepositoryMock */
        $walletRepositoryMock = $this->getMockBuilder(WalletRepository::class)->disableOriginalConstructor()->getMock();

        /** @var PHPUnit_Framework_MockObject_MockObject|OrderRepository $ordersProviderMock */
        $ordersProviderMock = $this->getMockBuilder(OrderRepository::class)->disableOriginalConstructor()->getMock();
        $ordersProviderMock->method('getPendingOrders')->willReturn($actualUserPlacesOrders);
        $ordersProviderMock->method('getSettlementsLoggedAfterId')->willReturn([]);

        $sut = new TradingOrderMatcher($ordersProviderMock, $walletRepositoryMock);
        $matchedOrders = $sut->match();

        $this->assertEquals($expectedMatchedOrders->getBuyOrders(), $matchedOrders->getBuyOrders());
        $this->assertEquals($expectedMatchedOrders->getSellOrders(), $matchedOrders->getSellOrders());
    }

    /**
     * @return array
     */
    public function testOrderProvider()
    {
        $testAsset = 1; // ex: uUSD
        return [
            'scenario_1_a_seller_get_settled_together_with_one_buyer' => [
                // actual user placed orders
                new Orders(
                    [
                        new BuyOrder(1, 1, $testAsset, 4.0000, 80, 0, Orders::STATUS_PENDING),
                        new BuyOrder(2, 1, $testAsset, 3.1000, 30, 0, Orders::STATUS_PENDING),
                    ],
                    [
                        new SellOrder(3, 990, $testAsset, 3.0050, 100, 0, Orders::STATUS_PENDING),
                    ]
                ),
                // expected matched orders with their balances
                new Orders(
                    [
                        new BuyOrder(1, 1, $testAsset, 4.0000, 80, 80, Orders::STATUS_PROCESSED),
                        new BuyOrder(2, 1, $testAsset, 3.1000, 30, 20, Orders::STATUS_PENDING),
                    ],
                    [
                        new SellOrder(3, 990, $testAsset, 3.0050, 100, 100, Orders::STATUS_PROCESSED),
                    ]
                ),
            ],
            'scenario_2_where_we_have_two_sellers_getting_settled' => [
                // actual user placed orders
                new Orders(
                    [
                        new BuyOrder(1, 1, $testAsset, 4.0000, 80, 0, Orders::STATUS_PENDING),
                        new BuyOrder(2, 1, $testAsset, 3.1000, 30, 0, Orders::STATUS_PENDING),
                    ],
                    [
                        new SellOrder(3, 990, $testAsset, 3.0050, 40, 0, Orders::STATUS_PENDING),
                        new SellOrder(4, 890, $testAsset, 3.0050, 30, 0, Orders::STATUS_PENDING),
                    ]
                ),
                // expected matched orders with their balances
                new Orders(
                    [
                        new BuyOrder(1, 1, $testAsset, 4.0000, 80, 70, Orders::STATUS_PENDING),
                        new BuyOrder(2, 1, $testAsset, 3.1000, 30, 0, Orders::STATUS_PENDING),
                    ],
                    [
                        new SellOrder(3, 990, $testAsset, 3.0050, 40, 40, Orders::STATUS_PROCESSED),
                        new SellOrder(4, 890, $testAsset, 3.0050, 30, 30, Orders::STATUS_PROCESSED),
                    ]
                ),
            ],
            'scenario_2_where_assets_does_not_match' => [ // selling different assets to buyer demands. in this case. nothing get settled
                // actual user placed orders
                new Orders(
                    [
                        new BuyOrder(1, 1, $testAsset, 4.0000, 80, 0, Orders::STATUS_PENDING),
                        new BuyOrder(2, 1, $testAsset, 3.1000, 30, 0, Orders::STATUS_PENDING),
                    ],
                    [
                        new SellOrder(3, 990, 99, 3.0050, 40, 0, Orders::STATUS_PENDING),
                        new SellOrder(4, 890, 99, 3.0050, 30, 0, Orders::STATUS_PENDING),
                    ]
                ),
                // expected matched orders with their balances
                new Orders(
                    [
                        new BuyOrder(1, 1, $testAsset, 4.0000, 80, 0, Orders::STATUS_PENDING),
                        new BuyOrder(2, 1, $testAsset, 3.1000, 30, 0, Orders::STATUS_PENDING),
                    ],
                    [
                        new SellOrder(3, 990, 99, 3.0050, 40, 0, Orders::STATUS_PENDING),
                        new SellOrder(4, 890, 99, 3.0050, 30, 0, Orders::STATUS_PENDING),
                    ]
                ),
            ],
        ];
    }
}
