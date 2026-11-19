<?php

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\Issuance\AssetIssuerAuthority;
use Hub\UltraCore\Issuance\IssuerSelectionStrategy;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\MatchEngine\Order\Orders;
use Hub\UltraCore\MatchEngine\Settlement\SettlementGuard;
use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Exchange;
use Hub\UltraCore\Money\Money;
use Hub\UltraCore\Support\FakeAssetsRepository;
use Hub\UltraCore\Support\InMemoryRunLock;
use Hub\UltraCore\Support\InMemoryWalletRepository;
use Hub\UltraCore\Support\LedgerFixture;
use Hub\UltraCore\Ven\UltraVenRepository;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

/**
 * The match engine against SQLite (orders, settlement keys, Ven ledger) and in-memory asset wallets:
 * duplicate runs, crashes and resumes never charge twice, and bonds are never bought back by the system account.
 */
final class SettlementSafetyTest extends TestCase
{
    const SYSTEM = 1;
    const BUYER = 10;
    const SELLER = 30;
    const ISSUER = 500;
    const ASSET = 7;   // 10 Ven per unit
    const BOND = 8;    // 100 Ven per unit (face value), category bond

    /** @var LedgerFixture */
    private $db;

    /** @var InMemoryWalletRepository */
    private $wallets;

    /** @var FakeAssetsRepository */
    private $assets;

    /** @var TestLogger */
    private $logger;

    /** @var \ArrayObject shared by every run's lock, like one GET_LOCK name */
    private $lockState;

    /** @var string */
    private $errorLog;

    protected function setUp(): void
    {
        // earlier tests (DefaultWalletHandlerTest) leave unverified Mockery expectations behind; start clean
        Mockery::resetContainer();
        $this->db = new LedgerFixture();
        $this->db->account(self::SYSTEM, 10000);
        $this->db->account(self::BUYER, 1000);
        $this->db->account(self::SELLER, 0);
        $this->db->account(self::ISSUER, 0);

        $this->wallets = new InMemoryWalletRepository();
        $this->assets = new FakeAssetsRepository();
        $this->assets->add(self::ASSET, 10);
        $this->assets->add(self::BOND, 100, 'bond');
        $this->logger = new TestLogger();
        $this->lockState = new \ArrayObject();

        $this->errorLog = ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'ultra-core-test'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
        Mockery::close();
    }

    // ------------------------------------------------------------------ wiring

    private function orderRepository()
    {
        return new OrderRepository($this->db->dbal, $this->logger);
    }

    private function guard()
    {
        return new SettlementGuard($this->db->dbal, $this->logger);
    }

    private function issuerMatcher(OrderRepository $orders, ?SettlementGuard $guard = null, ?SystemBuyBackPolicy $policy = null)
    {
        $strategy = Mockery::mock(IssuerSelectionStrategy::class);
        $strategy->shouldReceive('select')->andReturnUsing(function ($assetId, $quantity) {
            return [new AssetIssuerAuthority(self::ISSUER, 1000, 1000, $quantity)];
        });
        $exchange = Mockery::mock(Exchange::class);
        $exchange->shouldReceive('convertFromVenToOther')->andReturn(new Money(0.1, Currency::VEN()));

        return new BuyerIssuerMatcher(
            $exchange,
            $orders,
            $strategy,
            new UltraVenRepository($this->db->ledgerConnection(false), $this->logger),
            $this->assets,
            $this->wallets,
            $this->logger,
            Orders::MAX_MATCH_ATTEMPTS_PER_BUY_ORDER,
            $guard ?: $this->guard(),
            $policy
        );
    }

    private function engine(?OrderRepository $orders = null, ?SettlementGuard $guard = null)
    {
        $orders = $orders ?: $this->orderRepository();

        return new MatchEngine(
            new TradingOrderMatcher($orders, $this->wallets),
            $orders,
            $this->wallets,
            new UltraVenRepository($this->db->ledgerConnection(false), $this->logger),
            $this->issuerMatcher($orders, $guard),
            $this->logger,
            new InMemoryRunLock($this->lockState),
            $guard ?: $this->guard()
        );
    }

    /**
     * An order repository that keeps handing out the pending orders it read first: a second run that read them
     * at the same moment as the first, before the first one settled anything.
     */
    private function staleSnapshotRepository()
    {
        return new class($this->db->dbal, $this->logger) extends OrderRepository {
            private $snapshot;

            public function getPendingOrders()
            {
                if ($this->snapshot === null) {
                    $this->snapshot = parent::getPendingOrders();
                }

                return $this->snapshot;
            }
        };
    }

    private function crash()
    {
        return new \Error('the PHP process died here');
    }

    // ------------------------------------------------------------------ issuer purchases

    #[Test]
    public function aBuyOrderIsSettledFromIssuerSupplyOnce()
    {
        $order = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 5);

        $this->assertTrue($this->engine()->execute());

        $this->assertSame(950.0, $this->db->balance(self::BUYER));
        $this->assertSame(50.0, $this->db->balance(self::ISSUER));
        $this->assertSame(5.0, $this->wallets->unitsOf(self::BUYER, self::ASSET));
        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($order)['status']);
        $this->assertSame(SettlementGuard::KEY_COMPLETED, $this->db->keyRow('order:' . $order)['status']);

        // a later run finds nothing to do
        $this->assertTrue($this->engine()->execute());
        $this->assertSame(950.0, $this->db->balance(self::BUYER));
        $this->assertSame(1, $this->db->transactionCount());
    }

    #[Test]
    public function aDuplicateRunThatReadTheSameOrdersDoesNotChargeTwice()
    {
        $order = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 5);
        $orders = $this->staleSnapshotRepository();

        $this->issuerMatcher($orders)->execute();
        // the second run still believes the order is pending; its claim must fail
        $this->issuerMatcher($orders)->execute();

        $this->assertSame(950.0, $this->db->balance(self::BUYER));
        $this->assertSame(50.0, $this->db->balance(self::ISSUER));
        $this->assertSame(5.0, $this->wallets->unitsOf(self::BUYER, self::ASSET));
        $this->assertSame(1, $this->db->transactionCount());
        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($order)['status']);
    }

    #[Test]
    public function aSecondEngineRunWhileOneHoldsTheLockDoesNothing()
    {
        $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 5);
        $this->lockState['held'] = true; // another run is in progress

        $this->assertFalse($this->engine()->execute());

        $this->assertSame(1000.0, $this->db->balance(self::BUYER));
        $this->assertSame(0, $this->db->transactionCount());
        $this->assertTrue($this->logger->hasWarningThatContains('holds the lock'));
    }

    #[Test]
    public function withoutTheMigrationNothingIsMatchedOrPaid()
    {
        $buy = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 4, 0);
        $this->db->order(self::SELLER, self::ASSET, 'sell', 9, 4, 0);
        $this->wallets->give(self::SELLER, self::ASSET, 4);
        $this->db->pdo->exec('DROP TABLE ultra_order_settlement_keys');

        $this->assertFalse($this->engine()->execute());

        $this->assertSame(1000.0, $this->db->balance(self::BUYER));
        $this->assertSame(0, (int)$this->db->pdo->query('SELECT COUNT(*) FROM ultra_custom_buy_sell_order_settlements')->fetchColumn());
        $this->assertSame(Orders::STATUS_PENDING, $this->db->orderRow($buy)['status']);
        $this->assertTrue($this->logger->hasCriticalThatContains('Version20261119120000'));
        $this->assertFalse(!empty($this->lockState['held']), 'the lock is released');
    }

    #[Test]
    public function aCrashAfterTheVenMovedIsFlaggedForReviewAndNeverChargedAgain()
    {
        $order = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 5);
        $this->wallets->beforeMove = function ($op) {
            if ($op === 'credit') {
                throw $this->crash();
            }
        };
        try {
            $this->engine()->execute();
            $this->fail('the run should have died');
        } catch (\Error $e) {
            // the process is gone: Ven paid, units not credited, order still claimed
        }
        $this->assertSame(950.0, $this->db->balance(self::BUYER));
        $this->assertSame(Orders::STATUS_PROCESSING, $this->db->orderRow($order)['status']);

        $this->wallets->beforeMove = null;
        $this->db->ageClaims();
        $this->expectErrorLog();
        $this->assertTrue($this->engine()->execute());
        $this->assertTrue($this->engine()->execute());

        $this->assertSame(950.0, $this->db->balance(self::BUYER), 'charged once, never again');
        $this->assertSame(50.0, $this->db->balance(self::ISSUER));
        $this->assertSame(1, $this->db->transactionCount());
        $this->assertSame(0.0, $this->wallets->unitsOf(self::BUYER, self::ASSET), 'nothing guessed: a human credits the units');
        $this->assertSame(Orders::STATUS_REVIEW, $this->db->orderRow($order)['status']);
        $this->assertSame(SettlementGuard::KEY_REVIEW, $this->db->keyRow('order:' . $order)['status']);
        $this->assertTrue($this->logger->hasCriticalThatContains("order {$order} flagged for review"));
    }

    #[Test]
    public function aCrashAfterTheSettlementCompletedOnlyMarksTheOrderOnResume()
    {
        $order = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 5);
        $crashingGuard = new class($this->db->dbal, $this->logger) extends SettlementGuard {
            public function finishOrder($orderId, $token, $notes = '')
            {
                throw new \Error('the PHP process died before marking the order');
            }
        };
        try {
            $this->issuerMatcher($this->orderRepository(), $crashingGuard)->execute();
            $this->fail('the run should have died');
        } catch (\Error $e) {
        }
        $this->assertSame(Orders::STATUS_PROCESSING, $this->db->orderRow($order)['status']);

        $this->db->ageClaims();
        $this->assertTrue($this->engine()->execute());

        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($order)['status']);
        $this->assertSame(950.0, $this->db->balance(self::BUYER));
        $this->assertSame(5.0, $this->wallets->unitsOf(self::BUYER, self::ASSET));
        $this->assertSame(1, $this->db->transactionCount());
    }

    #[Test]
    public function aCrashBeforeAnyMoneyMovedIsReleasedAndSettledOnce()
    {
        $order = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 5);
        $crashingGuard = new class($this->db->dbal, $this->logger) extends SettlementGuard {
            public function beginSettlement($key, $kind, $orderId, $matchedOrderId, $token)
            {
                throw new \Error('the PHP process died after the claim');
            }
        };
        try {
            $this->issuerMatcher($this->orderRepository(), $crashingGuard)->execute();
            $this->fail('the run should have died');
        } catch (\Error $e) {
        }
        $this->assertSame(Orders::STATUS_PROCESSING, $this->db->orderRow($order)['status']);

        $this->db->ageClaims();
        $this->assertTrue($this->engine()->execute());

        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($order)['status']);
        $this->assertSame(950.0, $this->db->balance(self::BUYER));
        $this->assertSame(1, $this->db->transactionCount());
    }

    #[Test]
    public function aFreshClaimIsLeftAloneByRecovery()
    {
        $order = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 5);
        $this->guard()->claimOrder($order, 'another-live-run');

        $this->assertSame([], $this->guard()->recoverStaleClaims());
        $this->assertSame(Orders::STATUS_PROCESSING, $this->db->orderRow($order)['status']);
    }

    #[Test]
    public function aPaymentTheBuyerCannotCoverRejectsTheOrderAndMovesNothing()
    {
        $order = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 5);
        // the buyer spends the Ven between the balance check and the payment
        $this->db->pdo->exec('UPDATE venbalance SET balance = 20 WHERE user = ' . self::BUYER);

        $this->issuerMatcher($this->orderRepository())->execute();

        $this->assertSame(20.0, $this->db->balance(self::BUYER));
        $this->assertSame(0.0, $this->db->balance(self::ISSUER));
        $this->assertSame(Orders::STATUS_REJECTED, $this->db->orderRow($order)['status']);
    }

    // ------------------------------------------------------------------ order book pairs

    #[Test]
    public function anOrderBookPairIsPaidOnceEvenWhenTwoRunsSeeIt()
    {
        $buy = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 4, 0);
        $sell = $this->db->order(self::SELLER, self::ASSET, 'sell', 9, 4, 0);
        $this->wallets->give(self::SELLER, self::ASSET, 4);
        // both runs believe the pair is new (as two overlapping runs would)
        $orders = new class($this->db->dbal, $this->logger) extends OrderRepository {
            public function lastSettlementId()
            {
                return 0;
            }
        };

        $this->assertTrue($this->engine($orders)->execute());
        $this->assertTrue($this->engine($orders)->execute());

        $this->assertSame(960.0, $this->db->balance(self::BUYER));
        $this->assertSame(36.0, $this->db->balance(self::SELLER), 'their ask, 4 x 9');
        $this->assertSame(10004.0, $this->db->balance(self::SYSTEM), 'the spread, once');
        $this->assertSame(4.0, $this->wallets->unitsOf(self::BUYER, self::ASSET));
        $this->assertSame(0.0, $this->wallets->unitsOf(self::SELLER, self::ASSET));
        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($buy)['status']);
        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($sell)['status']);
    }

    #[Test]
    public function aPartlyMatchedBuyOrderBuysOnlyTheRestFromIssuers()
    {
        $buy = $this->db->order(self::BUYER, self::ASSET, 'buy', 10, 10, 2);
        $this->db->order(self::SELLER, self::ASSET, 'sell', 9, 4, 0);
        $this->wallets->give(self::SELLER, self::ASSET, 4);

        $this->assertTrue($this->engine()->execute());
        $this->assertTrue($this->engine()->execute());

        $this->assertSame(10.0, $this->wallets->unitsOf(self::BUYER, self::ASSET), '4 from the seller + 6 from the issuer, never 14');
        $this->assertSame(900.0, $this->db->balance(self::BUYER));
        $this->assertSame(60.0, $this->db->balance(self::ISSUER));
        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($buy)['status']);
        $this->assertSame('4.0000', sprintf('%.4F', $this->db->orderRow($buy)['settled_amount_so_far']));
    }

    // ------------------------------------------------------------------ system buy-back

    #[Test]
    public function theSystemAccountBuysBackAnUnmatchedSellOrder()
    {
        $sell = $this->db->order(self::SELLER, self::ASSET, 'sell', 9, 2);
        $this->wallets->give(self::SELLER, self::ASSET, 2);

        $this->issuerMatcher($this->orderRepository())->execute();

        $this->assertSame(20.0, $this->db->balance(self::SELLER));
        $this->assertSame(2.0, $this->wallets->unitsOf(self::SYSTEM, self::ASSET));
        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($sell)['status']);
    }

    #[Test]
    public function bondsAreNotBoughtBackByTheSystemAccount()
    {
        $sell = $this->db->order(self::SELLER, self::BOND, 'sell', 100, 3);
        $this->wallets->give(self::SELLER, self::BOND, 3);

        $this->issuerMatcher($this->orderRepository())->execute();

        $this->assertSame(0.0, $this->db->balance(self::SELLER));
        $this->assertSame(10000.0, $this->db->balance(self::SYSTEM));
        $this->assertSame(3.0, $this->wallets->unitsOf(self::SELLER, self::BOND));
        $this->assertSame(0.0, $this->wallets->unitsOf(self::SYSTEM, self::BOND));
        $this->assertSame(Orders::STATUS_PENDING, $this->db->orderRow($sell)['status'], 'stays on the order book');
        $this->assertTrue($this->logger->hasInfoThatContains('bonds are not bought back'));
    }

    #[Test]
    public function anAssetWithBondTermsIsNotBoughtBackWhateverItsCategory()
    {
        $this->assets->bondTerms[self::ASSET] = true;
        $sell = $this->db->order(self::SELLER, self::ASSET, 'sell', 9, 2);
        $this->wallets->give(self::SELLER, self::ASSET, 2);

        $this->issuerMatcher($this->orderRepository())->execute();

        $this->assertSame(0.0, $this->db->balance(self::SELLER));
        $this->assertSame(Orders::STATUS_PENDING, $this->db->orderRow($sell)['status']);
    }

    #[Test]
    public function whenItCannotBeToldWhetherAnAssetIsABondItIsNotBoughtBack()
    {
        $this->assets->bondTerms[self::ASSET] = null;
        $sell = $this->db->order(self::SELLER, self::ASSET, 'sell', 9, 2);
        $this->wallets->give(self::SELLER, self::ASSET, 2);

        $this->issuerMatcher($this->orderRepository())->execute();

        $this->assertSame(0.0, $this->db->balance(self::SELLER));
        $this->assertSame(Orders::STATUS_PENDING, $this->db->orderRow($sell)['status']);
    }

    #[Test]
    public function bondBuyBackCanBeAllowedExplicitly()
    {
        $sell = $this->db->order(self::SELLER, self::BOND, 'sell', 100, 1);
        $this->wallets->give(self::SELLER, self::BOND, 1);

        $this->issuerMatcher($this->orderRepository(), null, new SystemBuyBackPolicy($this->assets, true))->execute();

        $this->assertSame(100.0, $this->db->balance(self::SELLER));
        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($sell)['status']);
    }

    #[Test]
    public function aFailedBuyBackPayoutReturnsTheUnitsAndLeavesTheOrderPending()
    {
        $this->db->pdo->exec('UPDATE venbalance SET balance = 5 WHERE user = ' . self::SYSTEM);
        $sell = $this->db->order(self::SELLER, self::ASSET, 'sell', 9, 2);
        $this->wallets->give(self::SELLER, self::ASSET, 2);

        $this->issuerMatcher($this->orderRepository())->execute();

        $this->assertSame(2.0, $this->wallets->unitsOf(self::SELLER, self::ASSET));
        $this->assertSame(0.0, $this->db->balance(self::SELLER));
        $this->assertSame(Orders::STATUS_PENDING, $this->db->orderRow($sell)['status']);
        $this->assertSame(SettlementGuard::KEY_FAILED, $this->db->keyRow('order:' . $sell)['status']);

        // once the system account has the Ven, the next run settles it, once
        $this->db->pdo->exec('UPDATE venbalance SET balance = 10000 WHERE user = ' . self::SYSTEM);
        $this->issuerMatcher($this->orderRepository())->execute();
        $this->issuerMatcher($this->orderRepository())->execute();
        $this->assertSame(20.0, $this->db->balance(self::SELLER));
        $this->assertSame(Orders::STATUS_PROCESSED, $this->db->orderRow($sell)['status']);
    }
}
