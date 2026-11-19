<?php

namespace Hub\UltraCore\Support;

use Hub\UltraCore\Exception\InsufficientBalanceException;
use Hub\UltraCore\Exception\WalletException;
use Hub\UltraCore\Wallet\Wallet;
use Hub\UltraCore\Wallet\WalletRepository;
use Mockery;
use Mockery\MockInterface;

/**
 * Ultra asset wallets in memory. DefaultWalletHandlerTest declares Wallet as a Mockery named mock, so in a full
 * run the Wallet objects handed out are instances of that mock with the same answers.
 */
class InMemoryWalletRepository extends WalletRepository
{
    /** @var array "user:asset" => float units */
    public $units = array();

    /** @var array "user:asset" => int wallet id */
    private $ids = array();

    /** @var callable|null function(string $op, int $userId, int $assetId, float $amount): void, may throw */
    public $beforeMove;

    /** @var int credits and debits that went through */
    public $moves = 0;

    public function __construct()
    {
        // no database
    }

    public function give($userId, $assetId, $units)
    {
        $this->units[$userId . ':' . $assetId] = (float)$units;
    }

    public function unitsOf($userId, $assetId)
    {
        $key = $userId . ':' . $assetId;

        return isset($this->units[$key]) ? round($this->units[$key], 4) : 0.0;
    }

    public function getUserWallet($userId, $assetId)
    {
        $key = $userId . ':' . $assetId;
        if (!isset($this->units[$key])) {
            $this->units[$key] = 0.0;
        }
        if (!isset($this->ids[$key])) {
            $this->ids[$key] = count($this->ids) + 1;
        }
        if (!in_array(MockInterface::class, class_implements(Wallet::class), true)) {
            return new Wallet($this->ids[$key], (int)$userId, (int)$assetId, $this->units[$key], $this->units[$key], 'pk-' . $key);
        }

        // DefaultWalletHandlerTest ran first and declared Wallet as a Mockery named mock
        $units = &$this->units;
        $wallet = Mockery::namedMock(Wallet::class);
        $wallet->shouldReceive('getId')->andReturn($this->ids[$key]);
        $wallet->shouldReceive('getUserId')->andReturn((int)$userId);
        $wallet->shouldReceive('getAssetId')->andReturn((int)$assetId);
        $wallet->shouldReceive('getPublicKey')->andReturn('pk-' . $key);
        $wallet->shouldReceive('getBalance')->andReturnUsing(function () use (&$units, $key) {
            return $units[$key];
        });
        $wallet->shouldReceive('getAvailableBalance')->andReturnUsing(function () use (&$units, $key) {
            return $units[$key];
        });

        return $wallet;
    }

    public function getUserWalletOrNull($userId, $assetId)
    {
        return $this->getUserWallet($userId, $assetId);
    }

    public function credit(Wallet $wallet, $amount, array $metaData)
    {
        $this->move($wallet, (float)$amount, 'credit');
    }

    public function debit(Wallet $wallet, $amount, array $metaData)
    {
        $this->move($wallet, (float)$amount, 'debit');
    }

    private function move(Wallet $wallet, $amount, $op)
    {
        if (!is_finite($amount) || $amount <= 0) {
            throw new WalletException('A wallet amount must be greater than zero(0)');
        }
        if ($this->beforeMove) {
            call_user_func($this->beforeMove, $op, $wallet->getUserId(), $wallet->getAssetId(), $amount);
        }
        $key = $wallet->getUserId() . ':' . $wallet->getAssetId();
        if ($op === 'debit') {
            if ($this->units[$key] < $amount) {
                throw new InsufficientBalanceException("not enough units in {$key}");
            }
            $this->units[$key] -= $amount;
        } else {
            $this->units[$key] += $amount;
        }
        $this->moves++;
    }
}
