<?php

namespace Hub\UltraCore\Ven;

use Hub\UltraCore\Exception\VenLedgerInconsistentException;
use Hub\UltraCore\Exception\VenLegFailedException;
use Hub\UltraCore\Exception\VenTransferException;
use Hub\UltraCore\Support\LedgerFixture;
use Hub\UltraCore\Support\SqliteLedgerConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

/**
 * UltraVenRepository::sendVen on SQLite, both as MyISAM (no transactions: conditional writes and reversals) and
 * as InnoDB (one transaction, rolled back on failure).
 */
final class SendVenSafetyTest extends TestCase
{
    const SENDER = 10;
    const RECEIVER = 20;

    /** @var LedgerFixture */
    private $db;

    /** @var TestLogger */
    private $logger;

    /** @var string */
    private $errorLog;

    protected function setUp(): void
    {
        $this->db = new LedgerFixture();
        $this->db->account(self::SENDER, 50);
        $this->db->account(self::RECEIVER, 5);
        $this->logger = new TestLogger();
        // the CRITICAL records also go to error_log: keep them out of the test output
        $this->errorLog = ini_get('error_log');
        ini_set('error_log', tempnam(sys_get_temp_dir(), 'ultra-core-test'));
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->errorLog);
    }

    public static function engines()
    {
        return ['MyISAM (no transactions)' => [false], 'InnoDB (transaction)' => [true]];
    }

    private function repository(SqliteLedgerConnection $connection)
    {
        return new UltraVenRepository($connection, $this->logger);
    }

    private function assertNothingMoved()
    {
        $this->assertSame(50.0, $this->db->balance(self::SENDER));
        $this->assertSame(5.0, $this->db->balance(self::RECEIVER));
        $this->assertSame(0, $this->db->transactionCount());
    }

    #[Test]
    #[DataProvider('engines')]
    public function aTransferMovesExactlyTheAmountAndWritesOneReceipt($transactional)
    {
        $id = $this->repository($this->db->ledgerConnection($transactional))->sendVen(self::SENDER, self::RECEIVER, '12.50', 'test');

        $this->assertGreaterThan(0, $id);
        $this->assertSame(37.5, $this->db->balance(self::SENDER));
        $this->assertSame(17.5, $this->db->balance(self::RECEIVER));
        $this->assertSame(1, $this->db->transactionCount());
    }

    #[Test]
    #[DataProvider('engines')]
    public function theConditionalDebitRefusesInsufficientFunds($transactional)
    {
        try {
            $this->repository($this->db->ledgerConnection($transactional))->sendVen(self::SENDER, self::RECEIVER, 50.01);
            $this->fail('A send above the balance must be refused');
        } catch (VenTransferException $e) {
            $this->assertSame(VenTransferException::INSUFFICIENT_FUNDS, $e->reason());
            $this->assertNotInstanceOf(VenLegFailedException::class, $e);
            $this->assertStringContainsString('Insufficient VEN balance of 50', $e->getMessage());
        }
        $this->assertNothingMoved();
    }

    #[Test]
    public function aBalanceCanNeverBeSpentTwice()
    {
        $repository = $this->repository($this->db->ledgerConnection(false));
        $repository->sendVen(self::SENDER, self::RECEIVER, 30);
        $this->expectException(VenTransferException::class);
        try {
            $repository->sendVen(self::SENDER, self::RECEIVER, 30);
        } finally {
            $this->assertSame(20.0, $this->db->balance(self::SENDER));
            $this->assertSame(35.0, $this->db->balance(self::RECEIVER));
            $this->assertSame(1, $this->db->transactionCount());
        }
    }

    #[Test]
    #[DataProvider('engines')]
    public function aFailedCreditReversesTheDebit($transactional)
    {
        $connection = $this->db->ledgerConnection($transactional);
        $connection->failWhen(function ($sql, $params) {
            return strpos($sql, '`balance` + ?') !== false && (int)$params[1] === self::RECEIVER;
        });

        try {
            $this->repository($connection)->sendVen(self::SENDER, self::RECEIVER, 20);
            $this->fail('A failed credit must throw');
        } catch (VenLegFailedException $e) {
            $this->assertNotInstanceOf(VenLedgerInconsistentException::class, $e);
            $this->assertSame(VenTransferException::CREDIT_FAILED, $e->reason());
            $this->assertFalse($e->isUncertain());
        }
        $this->assertNothingMoved();
    }

    #[Test]
    #[DataProvider('engines')]
    public function aFailedReceiptReversesTheDebit($transactional)
    {
        $connection = $this->db->ledgerConnection($transactional);
        $connection->failWhen(function ($sql) {
            return strpos($sql, 'INSERT INTO `transactions`') === 0;
        });

        try {
            $this->repository($connection)->sendVen(self::SENDER, self::RECEIVER, 20);
            $this->fail('A failed receipt must throw');
        } catch (VenLegFailedException $e) {
            $this->assertSame(VenTransferException::RECEIPT_FAILED, $e->reason());
        }
        $this->assertNothingMoved();
    }

    #[Test]
    public function whenEvenTheReversalFailsItIsCriticalWithARecoverableRecord()
    {
        $connection = $this->db->ledgerConnection(false);
        // every credit fails: the receiver's, then the sender's put-back
        $connection->failWhen(function ($sql) {
            return strpos($sql, '`balance` + ?') !== false;
        });

        try {
            $this->repository($connection)->sendVen(self::SENDER, self::RECEIVER, 20);
            $this->fail('An unreversed debit must throw');
        } catch (VenLedgerInconsistentException $e) {
            $this->assertTrue($e->isUncertain());
            $this->assertSame(VenTransferException::INCONSISTENT, $e->reason());
            $this->assertSame(self::SENDER, $e->record()['from']);
            $this->assertSame('20.00', $e->record()['amount']);
        }
        $this->assertTrue($this->logger->hasCriticalThatContains('could NOT be reversed'));
        $critical = array_values(array_filter($this->logger->records, function ($r) {
            return $r['level'] === 'critical';
        }));
        $this->assertSame('debited-not-credited', $critical[0]['context']['state']);
        $this->assertStringContainsString('credit 20.00 Ven to user 10', $critical[0]['context']['repair']);
        // the receiver got nothing: the Ven is short, never doubled, and the log says how to repair it
        $this->assertSame(5.0, $this->db->balance(self::RECEIVER));
    }

    #[Test]
    public function aRollbackThatFailsIsCritical()
    {
        $connection = $this->db->ledgerConnection(true);
        $connection->failRollback = true;
        $connection->failWhen(function ($sql, $params) {
            return strpos($sql, '`balance` + ?') !== false && (int)$params[1] === self::RECEIVER;
        });

        $this->expectException(VenLedgerInconsistentException::class);
        $this->repository($connection)->sendVen(self::SENDER, self::RECEIVER, 20);
    }

    #[Test]
    public function aFailedCommitIsNeverReportedAsSuccess()
    {
        $connection = $this->db->ledgerConnection(true);
        $connection->failCommit = true;

        try {
            $this->repository($connection)->sendVen(self::SENDER, self::RECEIVER, 20);
            $this->fail('An unconfirmed commit must throw');
        } catch (VenLedgerInconsistentException $e) {
            $this->assertTrue($this->logger->hasCriticalThatContains('commit outcome unknown'));
        }
    }

    public static function refusedAmounts()
    {
        return [
            'negative' => [-5],
            'negative string' => ['-1e3'],
            'zero' => [0],
            'NaN' => [NAN],
            'infinite' => [INF],
            'three decimals' => [1.234],
            'three decimals string' => ['1.005'],
            'not a number' => ['abc'],
            'null' => [null],
            'bool' => [true],
        ];
    }

    #[Test]
    #[DataProvider('refusedAmounts')]
    public function onlyPositiveFiniteAmountsWithAtMostTwoDecimalsMove($amount)
    {
        try {
            $this->repository($this->db->ledgerConnection(false))->sendVen(self::SENDER, self::RECEIVER, $amount);
            $this->fail('The amount must be refused');
        } catch (VenTransferException $e) {
            $this->assertSame(VenTransferException::INVALID_AMOUNT, $e->reason());
        }
        $this->assertNothingMoved();
    }

    #[Test]
    public function noSelfTransfer()
    {
        try {
            $this->repository($this->db->ledgerConnection(false))->sendVen(self::SENDER, (string)self::SENDER, 1);
            $this->fail('A self-transfer must be refused');
        } catch (VenTransferException $e) {
            $this->assertSame(VenTransferException::SELF_TRANSFER, $e->reason());
        }
        $this->assertNothingMoved();
    }

    #[Test]
    public function aReceiverWithoutAnAccountIsRefusedBeforeAnyDebit()
    {
        try {
            $this->repository($this->db->ledgerConnection(false))->sendVen(self::SENDER, 999, 1);
            $this->fail('A send to a member without a Ven account must be refused');
        } catch (VenTransferException $e) {
            $this->assertSame(VenTransferException::NO_ACCOUNT, $e->reason());
        }
        $this->assertNothingMoved();
    }

    #[Test]
    public function floatNoiseIsNotAThirdDecimal()
    {
        $this->repository($this->db->ledgerConnection(false))->sendVen(self::SENDER, self::RECEIVER, 0.1 + 0.2);
        $this->assertSame(49.7, $this->db->balance(self::SENDER));
    }

    #[Test]
    public function aMysqliConnectionIsWrappedAndAnythingElseRefused()
    {
        $this->expectException(\InvalidArgumentException::class);
        new UltraVenRepository(new \stdClass());
    }
}
