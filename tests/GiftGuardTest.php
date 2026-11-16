<?php

namespace Hub\UltraCore;

use Hub\UltraCore\Exception\InsufficientBalanceException;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\Money\Exchange;
use Hub\UltraCore\Ven\UltraVenRepository;
use Hub\UltraCore\Wallet\Wallet;
use Hub\UltraCore\Wallet\WalletRepository;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * Wallet is built with Mockery::namedMock, as DefaultWalletHandlerTest does: the two can't mix a real Wallet and a named mock.
 */
class GiftGuardTest extends TestCase
{

    private function wallet()
    {
        $wallet = Mockery::namedMock(Wallet::class);
        $wallet->shouldReceive('getUserId')->andReturn(10);
        $wallet->shouldReceive('getAssetId')->andReturn(2);
        return $wallet;
    }

    private function handler($walletRepository)
    {
        return new DefaultWalletHandler(
            Mockery::mock(UltraVenRepository::class),
            Mockery::mock(UltraAssetsRepository::class),
            $walletRepository,
            Mockery::mock(OrderRepository::class),
            Mockery::mock(Exchange::class)
        );
    }

    public static function badAmounts()
    {
        return array(array(-10), array(0), array('-1e3'), array(NAN), array(INF), array('abc'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('badAmounts')]
    public function aTransferMustBePositive($amount)
    {
        $wallets = Mockery::mock(WalletRepository::class);
        $wallets->shouldNotReceive('credit');
        $wallets->shouldNotReceive('debit');
        $this->expectException(InsufficientBalanceException::class);
        $this->handler($wallets)->gift($this->wallet(), 20, $amount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function noTransferToYourself()
    {
        $wallets = Mockery::mock(WalletRepository::class);
        $wallets->shouldNotReceive('credit');
        $this->expectException(InsufficientBalanceException::class);
        $this->handler($wallets)->gift($this->wallet(), 10, 5);
    }
}
