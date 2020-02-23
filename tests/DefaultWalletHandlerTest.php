<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 10-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Issuance\AssetIssuerAuthority;
use Hub\UltraCore\Issuance\IssuerSelectionStrategy;
use Hub\UltraCore\MatchEngine\Order\BuyOrder;
use Hub\UltraCore\MatchEngine\Order\OrderRepository;
use Hub\UltraCore\MatchEngine\Order\Orders;
use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Exchange;
use Hub\UltraCore\Money\Money;
use Hub\UltraCore\Ven\UltraVenRepository;
use Hub\UltraCore\Ven\VenWallet;
use Hub\UltraCore\Wallet\Wallet;
use Hub\UltraCore\Wallet\WalletRepository;
use Mockery\LegacyMockInterface;
use PHPUnit\Framework\TestCase;
use Mockery;

class DefaultWalletHandlerTest extends TestCase
{
    /** @var array */
    private $testCurrencies = [
        ['secondary_currency' => 'XAU', 'current_amount' => 0.0000762543],
        ['secondary_currency' => 'ETH', 'current_amount' => 0.0001658963],
        ['secondary_currency' => 'CAD', 'current_amount' => 0.1262628972],
    ];

    /**
     * @test
     */
    public function shouldReturnTheCorrectAssetAmountUsingAllWeightingConfig()
    {
        $testAssetId = 99999;
        $testAvailableAssetBalance = 10;
        $testAssetTitle = 'TESTASSET';
        $testAssetBuyerUserId = 111;
        $testAssetBuyerUserVenBalance = 200.01;
        $testAssetIssuerUserId = 1062;
        $testVenAmountForOneAsset = 100;
        $testPurchaseAssetAmount = 2;

        $testWeightings = [
            new UltraAssetWeighting('CAD', $this->testCurrencies[2]['current_amount'], 37),
            new UltraAssetWeighting('ETH', $this->testCurrencies[1]['current_amount'], 13),
            new UltraAssetWeighting('XAU', $this->testCurrencies[0]['current_amount'], 50),
        ];
        $weightingConfig = [];
        array_walk($testWeightings, function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
            $weightingConfig[] = $weighting->toArray();
        });

        // VenRepository
        $balanceMock = Mockery::mock(VenWallet::class);
        $balanceMock->shouldReceive('getBalance')->once()->andReturn($testAssetBuyerUserVenBalance);
        $venRepoMock = Mockery::mock(UltraVenRepository::class);
        $venRepoMock->shouldReceive('getVenWalletOfUser')->once()->andReturn($balanceMock);
        $venRepoMock->shouldReceive('sendVen')->once()->with(
            $testAssetBuyerUserId,
            $testAssetIssuerUserId,
            $testVenAmountForOneAsset * $testPurchaseAssetAmount,
            "Purchased an amount of {$testPurchaseAssetAmount} {$testAssetTitle} assets @{$testVenAmountForOneAsset} VEN per 1 {$testAssetTitle}. Click <a href='/markets/my-wallets/transactions?id=1'>here</a> for more info."
        );

        // UltraAsset
        $assetMock = Mockery::mock(UltraAsset::class);
        $assetMock->shouldReceive('id')->once()->andReturn($testAssetId);
        $assetMock->shouldReceive('title')->once()->andReturn($testAssetTitle);
        $assetMock->shouldReceive('authorityUserId')->once()->andReturn($testAssetIssuerUserId);
        $assetMock->shouldReceive('numAssets')->twice()->andReturn($testAvailableAssetBalance);
        $assetMock->shouldReceive('getCurrency')->once()->andReturn(Currency::VEN());
        $assetMock->shouldReceive('weightings')->once()->andReturn($testWeightings);
        $assetMock->shouldReceive('tickerSymbol')->once()->andReturn('uTICK');
        $assetMock->shouldReceive('isWithOneWeighting')->once()->andReturn(false);
        $assetMock->shouldReceive('weightingType')->once()->andReturn('currency_combination');

        // Exchange
        $exchangeMock = Mockery::mock(Exchange::class);
        $exchangeMock->shouldReceive('convertToVen')->andReturn(new Money($testVenAmountForOneAsset, Currency::VEN()));
        $exchangeMock->shouldReceive('convertFromVenToOther')
            ->andReturn(new Money(0.1, Currency::custom('uTICK'))); // ASSET AMOUNT

        // UltraAssetsRepository
        $assetRepoMock = Mockery::mock(UltraAssetsRepository::class);
        $assetRepoMock->shouldReceive('getAssetWeightings')->once()->andReturn($testWeightings);
        $assetRepoMock->shouldReceive('deductTotalAssetQuantityBy')->once()->with($testAssetId, $testPurchaseAssetAmount);
        $assetRepoMock->shouldReceive('deductAssetQuantityBy')
            ->once()
            ->with($testAssetIssuerUserId, $testAssetId, $testPurchaseAssetAmount);

        // WalletRepository
        $walletMock = Mockery::namedMock(Wallet::class);
        $walletMock->shouldReceive('getBalance')->once()->andReturn(1);
        $walletMock->shouldReceive('getId')->once()->andReturn(1);
        //$walletMock->shouldReceive('save')->withNoArgs()->once();
        $walletRepoMock = Mockery::mock(WalletRepository::class);
        $walletRepoMock->shouldReceive('getUserWallet')->once()->andReturn($walletMock);
        $walletRepoMock->shouldReceive('credit')->once()->with(
            $walletMock,
            $testPurchaseAssetAmount,
            array(
                'asset_amount_in_ven' => $testVenAmountForOneAsset * $testPurchaseAssetAmount,
                'asset_amount_for_one_ven' => 0.1, // ASSET AMOUNT
                'ven_amount_for_one_asset' => $testVenAmountForOneAsset,
                'weightingConfig' => $weightingConfig,
                'commit' => true,
            )
        );

        /** @var OrderRepository|LegacyMockInterface $orderRepoMock */
        $orderRepoMock = Mockery::mock(OrderRepository::class);
        $orderRepoMock
            ->shouldReceive('addBuyOrder')
            ->with(
                Mockery::on(function (BuyOrder $argument) use (
                    $testPurchaseAssetAmount,
                    $testAssetBuyerUserId,
                    $testVenAmountForOneAsset,
                    $testAssetId
                ) {
                    return $argument->getId() === 0
                        && $argument->getUserId() === $testAssetBuyerUserId
                        && $argument->getAssetId() === $testAssetId
                        && $argument->getOfferingRate() === floatval($testVenAmountForOneAsset)
                        && $argument->getAmount() === floatval($testPurchaseAssetAmount)
                        && $argument->getSettledAmountSoFar() === 0.0
                        && $argument->getStatus() === Orders::STATUS_PENDING
                    ;
                }),
                $testVenAmountForOneAsset
            );

        $sut = new DefaultWalletHandler($venRepoMock, $assetRepoMock, $walletRepoMock, $orderRepoMock, $exchangeMock);
        $sut->purchase($testAssetBuyerUserId, $assetMock, $testPurchaseAssetAmount);
    }

    /**
     * @test
     * @expectedException \Hub\UltraCore\Exception\InsufficientVenBalanceException
     * @expectedExceptionMessage Current VEN Balance of '90' is not sufficient to buy the assets worth '100' VEN
     */
    public function shouldThrowExceptionWhenNoVenBalanceLeft()
    {
        $currentUserVenBalance = 90; // please note that current test user's ven balance is less then the asset amount in ven
        $oneAssetInVen = 100;
        $testUserId = 111;
        $testPurchaseAssetAmount = 1;

        // VenRepository
        $balanceMock = Mockery::mock('\Hub\HubCulture\Model\Eloquent\Venbalance');
        $balanceMock->shouldReceive('getBalance')->twice()->andReturn($currentUserVenBalance);
        $venRepoMock = Mockery::mock(UltraVenRepository::class);
        $venRepoMock->shouldReceive('getVenWalletOfUser')->once()->andReturn($balanceMock);

        // UltraAssetsRepository
        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('numAssets')->once();
        $assetMock->shouldReceive('weightings')->once()->andReturn([]);
        $assetMock->shouldReceive('getCurrency')->once()->andReturn(Currency::VEN());
        $assetMock->shouldReceive('isWithOneWeighting')->once()->andReturn(false);
        $assetMock->shouldReceive('weightingType')->once()->andReturn('currency_combination');
        $assetRepoMock = Mockery::mock('\Hub\UltraCore\UltraAssetsRepository');

        // Exchange
        $exchangeMock = Mockery::mock(Exchange::class);
        $exchangeMock->shouldReceive('convertToVen')->andReturn(new Money($oneAssetInVen, Currency::VEN()));
        $exchangeMock->shouldReceive('convertFromVenToOther')->andReturn(new Money(0.1, Currency::custom('uTICK')));

        // WalletRepository
        $walletRepoMock = Mockery::mock(WalletRepository::class);

        /** @var OrderRepository|LegacyMockInterface $orderRepoMock */
        $orderRepoMock = Mockery::mock(OrderRepository::class);
        $orderRepoMock->shouldReceive('addBuyOrder')->never();

        $sut = new DefaultWalletHandler($venRepoMock, $assetRepoMock, $walletRepoMock, $orderRepoMock, $exchangeMock);
        $sut->purchase($testUserId, $assetMock, $testPurchaseAssetAmount);
    }

    /**
     * @test
     * @expectedException \Hub\UltraCore\Exception\InsufficientAssetAvailabilityException
     * @expectedExceptionMessage There are no such amount of assets available for your requested amount of 12. Only 11
     *                           available.
     */
    public function shouldThrowExceptionWhenTryingToPurchaseMoreAssetsThanTheAvailableAmount()
    {
        $oneAssetInVen = 8;
        $testUserId = 111;
        $totalAvailableAssetAmount = 11;
        $testAssetBuyAmount = 12;
        $currentUserVenBalance = 96; // 12 * 8 = 96 VEN

        // VenRepository
        $balanceMock = Mockery::mock('\Hub\HubCulture\Model\Eloquent\Venbalance');
        $balanceMock->shouldReceive('getBalance')->twice()->andReturn($currentUserVenBalance);
        $venRepoMock = Mockery::mock(UltraVenRepository::class);
        $venRepoMock->shouldReceive('getVenWalletOfUser')->once()->andReturn($balanceMock);

        // UltraAssetsRepository
        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('numAssets')->once()->andReturn($totalAvailableAssetAmount);
        $assetMock->shouldReceive('weightings')->once()->andReturn([]);
        $assetMock->shouldReceive('getCurrency')->once()->andReturn(Currency::VEN());
        $assetMock->shouldReceive('isWithOneWeighting')->once()->andReturn(false);
        $assetMock->shouldReceive('weightingType')->once()->andReturn('currency_combination');
        $assetRepoMock = Mockery::mock('\Hub\UltraCore\UltraAssetsRepository');

        // Exchange
        $exchangeMock = Mockery::mock(Exchange::class);
        $exchangeMock->shouldReceive('convertToVen')->andReturn(new Money($oneAssetInVen, Currency::VEN()));

        // WalletRepository
        $walletRepoMock = Mockery::mock(WalletRepository::class);

        /** @var OrderRepository|LegacyMockInterface $orderRepoMock */
        $orderRepoMock = Mockery::mock(OrderRepository::class);
        $orderRepoMock->shouldReceive('addBuyOrder')->never();

        $sut = new DefaultWalletHandler($venRepoMock, $assetRepoMock, $walletRepoMock, $orderRepoMock, $exchangeMock);
        $sut->purchase($testUserId, $assetMock, $testAssetBuyAmount);
    }

    /**
     * @test
     */
    public function shouldUseTheCustomVenAmountWhenPurchasingSuchAnAsset()
    {
        $testAssetId = 99999;
        $testAvailableAssetBalance = 10;
        $testAssetTitle = 'TESTASSET';
        $testAssetBuyerUserId = 111;
        $testAssetBuyerUserVenBalance = 200.01;
        $testAssetIssuerUserId = 1062;
        $testVenAmountForOneAsset = 100;
        $testPurchaseAssetAmount = 2;

        $testWeightings = [
            new UltraAssetWeighting('Ven', $testVenAmountForOneAsset, 100),
        ];
        $weightingConfig = [];
        array_walk($testWeightings, function (UltraAssetWeighting $weighting) use (&$weightingConfig) {
            $weightingConfig[] = $weighting->toArray();
        });

        // VenRepository
        $balanceMock = Mockery::mock(VenWallet::class);
        $balanceMock->shouldReceive('getBalance')->once()->andReturn($testAssetBuyerUserVenBalance);
        $venRepoMock = Mockery::mock(UltraVenRepository::class);
        $venRepoMock->shouldReceive('getVenWalletOfUser')->once()->andReturn($balanceMock);
        $venRepoMock->shouldReceive('sendVen')->once()->with(
            $testAssetBuyerUserId,
            $testAssetIssuerUserId,
            $testVenAmountForOneAsset * $testPurchaseAssetAmount,
            "Purchased an amount of {$testPurchaseAssetAmount} {$testAssetTitle} assets @{$testVenAmountForOneAsset} VEN per 1 {$testAssetTitle}. Click <a href='/markets/my-wallets/transactions?id=1'>here</a> for more info."
        );

        // UltraAsset
        $assetMock = Mockery::mock(UltraAsset::class);
        $assetMock->shouldReceive('id')->once()->andReturn($testAssetId);
        $assetMock->shouldReceive('title')->once()->andReturn($testAssetTitle);
        $assetMock->shouldReceive('authorityUserId')->once()->andReturn($testAssetIssuerUserId);
        $assetMock->shouldReceive('numAssets')->twice()->andReturn($testAvailableAssetBalance);
        $assetMock->shouldReceive('getCurrency')->once()->andReturn(Currency::VEN());
        $assetMock->shouldReceive('weightings')->once()->andReturn($testWeightings);
        $assetMock->shouldReceive('tickerSymbol')->once()->andReturn('uTICK');
        // NOTE: Please note that a a custom ven amount based asset pricing is configured below.
        $assetMock->shouldReceive('weightingType')->once()->andReturn(UltraAssetsRepository::TYPE_VEN_AMOUNT);
        $assetMock->shouldReceive('isWithOneWeighting')->once()->andReturn(true);

        // Exchange
        $exchangeMock = Mockery::mock(Exchange::class);
        $exchangeMock->shouldReceive('convertFromVenToOther')
            ->andReturn(new Money(0.1, Currency::custom('Ven'))); // ASSET AMOUNT

        // UltraAssetsRepository
        $assetRepoMock = Mockery::mock(UltraAssetsRepository::class);
        $assetRepoMock->shouldReceive('getAssetWeightings')->once()->andReturn($testWeightings);
        $assetRepoMock->shouldReceive('deductTotalAssetQuantityBy')->once()->with($testAssetId, $testPurchaseAssetAmount);
        $assetRepoMock->shouldReceive('deductAssetQuantityBy')
            ->once()
            ->with($testAssetIssuerUserId, $testAssetId, $testPurchaseAssetAmount);

        // WalletRepository
        $walletMock = Mockery::namedMock(Wallet::class);
        $walletMock->shouldReceive('getBalance')->once()->andReturn(1);
        $walletMock->shouldReceive('getId')->once()->andReturn(1);
        //$walletMock->shouldReceive('save')->withNoArgs()->once();
        $walletRepoMock = Mockery::mock(WalletRepository::class);
        $walletRepoMock->shouldReceive('getUserWallet')->once()->andReturn($walletMock);
        $walletRepoMock->shouldReceive('credit')->once()->with(
            $walletMock,
            $testPurchaseAssetAmount,
            array(
                'asset_amount_in_ven' => $testVenAmountForOneAsset * $testPurchaseAssetAmount,
                'asset_amount_for_one_ven' => '0.1000', // ASSET AMOUNT
                'ven_amount_for_one_asset' => $testVenAmountForOneAsset,
                'weightingConfig' => $weightingConfig,
                'commit' => true,
            )
        );

        /** @var OrderRepository|LegacyMockInterface $orderRepoMock */
        $orderRepoMock = Mockery::mock(OrderRepository::class);
        $orderRepoMock
            ->shouldReceive('addBuyOrder')
            ->with(
                Mockery::on(function (BuyOrder $argument) use (
                    $testPurchaseAssetAmount,
                    $testAssetBuyerUserId,
                    $testVenAmountForOneAsset,
                    $testAssetId
                ) {
                    return $argument->getId() === 0
                        && $argument->getUserId() === $testAssetBuyerUserId
                        && $argument->getAssetId() === $testAssetId
                        && $argument->getOfferingRate() === floatval($testVenAmountForOneAsset)
                        && $argument->getAmount() === floatval($testPurchaseAssetAmount)
                        && $argument->getSettledAmountSoFar() === 0.0
                        && $argument->getStatus() === Orders::STATUS_PENDING
                        ;
                }),
                $testVenAmountForOneAsset
            );

        $sut = new DefaultWalletHandler($venRepoMock, $assetRepoMock, $walletRepoMock, $orderRepoMock, $exchangeMock);
        $sut->purchase($testAssetBuyerUserId, $assetMock, $testPurchaseAssetAmount);
    }
}
