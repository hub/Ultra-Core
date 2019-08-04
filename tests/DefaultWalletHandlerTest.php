<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 10-06-2018
 */

namespace Hub\UltraCore;

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
        $balanceMock = Mockery::mock('\Hub\UltraCore\Model\Eloquent\Venbalance');
        $balanceMock->shouldReceive('balance')->once()->andReturn($testAssetBuyerUserVenBalance);
        $venRepoMock = Mockery::mock('\Hub\UltraCore\Repository\VenRepository');
        $venRepoMock->shouldReceive('getVenBalanceOfUser')->once()->andReturn($balanceMock);
        $venRepoMock->shouldReceive('sendVen')->once()->with(
            $testAssetBuyerUserId,
            $testAssetIssuerUserId,
            $testVenAmountForOneAsset * $testPurchaseAssetAmount,
            "Purchased an amount of {$testPurchaseAssetAmount} {$testAssetTitle} assets @{$testVenAmountForOneAsset} VEN per 1 {$testAssetTitle}. Click <a href='/markets/my-wallets/transactions?id=1'>here</a> for more info."
        );

        // UltraAssetsRepository
        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('id')->once()->andReturn($testAssetId);
        $assetMock->shouldReceive('title')->once()->andReturn($testAssetTitle);
        $assetMock->shouldReceive('authorityUserId')->once()->andReturn($testAssetIssuerUserId);
        $assetMock->shouldReceive('numAssets')->twice()->andReturn($testAvailableAssetBalance );
        $assetMock->shouldReceive('weightings')->once()->andReturn($testWeightings);
        $assetRepoMock = Mockery::mock('\Hub\UltraCore\UltraAssetsRepository');
        $assetRepoMock->shouldReceive('getVenAmountForOneAsset')->once()->with($assetMock)->andReturn($testVenAmountForOneAsset);
        $assetRepoMock->shouldReceive('getAssetAmountForOneVen')->once()->andReturn(0.1); // ASSET AMOUNT
        $assetRepoMock->shouldReceive('getAssetWeightings')->once()->andReturn($testWeightings);
        $assetRepoMock->shouldReceive('deductAssetQuantityBy')->once()->with($testAssetId, $testPurchaseAssetAmount);

        // WalletRepository
        $walletMock = Mockery::namedMock('\Hub\UltraCore\Model\Eloquent\Wallet');
        $walletMock->shouldReceive('getBalance')->once()->andReturn(1);
        $walletMock->shouldReceive('getId')->once()->andReturn(1);
        //$walletMock->shouldReceive('save')->withNoArgs()->once();
        $walletRepoMock = Mockery::mock('\Hub\UltraCore\Repository\WalletRepository');
        $walletRepoMock->shouldReceive('getUserWallet')->once()->andReturn($walletMock);
        $walletRepoMock->shouldReceive('credit')->once()->with(
            $walletMock,
            $testPurchaseAssetAmount,
            array(
                'asset_amount_in_ven' => $testVenAmountForOneAsset * $testPurchaseAssetAmount,
                'asset_amount_for_one_ven' => 0.1, // ASSET AMOUNT
                'ven_amount_for_one_asset' => $testVenAmountForOneAsset,
                'weightingConfig' => $weightingConfig
            )
        );

        $sut = new DefaultWalletHandler($venRepoMock, $assetRepoMock, $walletRepoMock);
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
        $balanceMock = Mockery::mock('\Hub\UltraCore\Model\Eloquent\Venbalance');
        $balanceMock->shouldReceive('balance')->twice()->andReturn($currentUserVenBalance);
        $venRepoMock = Mockery::mock('\Hub\UltraCore\Repository\VenRepository');
        $venRepoMock->shouldReceive('getVenBalanceOfUser')->once()->andReturn($balanceMock);

        // UltraAssetsRepository
        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('numAssets')->once();
        $assetRepoMock = Mockery::mock('\Hub\UltraCore\UltraAssetsRepository');
        $assetRepoMock->shouldReceive('getVenAmountForOneAsset')->once()->with($assetMock)->andReturn($oneAssetInVen);

        // WalletRepository
        $walletRepoMock = Mockery::mock('\Hub\UltraCore\Repository\WalletRepository');

        $sut = new DefaultWalletHandler($venRepoMock, $assetRepoMock, $walletRepoMock);
        $sut->purchase($testUserId, $assetMock, $testPurchaseAssetAmount);
    }

    /**
     * @test
     * @expectedException \Hub\UltraCore\Exception\InsufficientAssetAvailabilityException
     * @expectedExceptionMessage There are no such amount of assets available for your requested amount of 12. Only 11 available.
     */
    public function shouldThrowExceptionWhenTryingToPurchaseMoreAssetsThenTheAvailableAmount()
    {
        $oneAssetInVen = 8;
        $testUserId = 111;
        $totalAvailableAssetAmount = 11;
        $testAssetBuyAmount = 12;
        $currentUserVenBalance = 96; // 12 * 8 = 96 VEN

        // VenRepository
        $balanceMock = Mockery::mock('\Hub\UltraCore\Model\Eloquent\Venbalance');
        $balanceMock->shouldReceive('balance')->twice()->andReturn($currentUserVenBalance);
        $venRepoMock = Mockery::mock('\Hub\UltraCore\Repository\VenRepository');
        $venRepoMock->shouldReceive('getVenBalanceOfUser')->once()->andReturn($balanceMock);

        // UltraAssetsRepository
        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('numAssets')->once()->andReturn($totalAvailableAssetAmount);
        $assetRepoMock = Mockery::mock('\Hub\UltraCore\UltraAssetsRepository');
        $assetRepoMock->shouldReceive('getVenAmountForOneAsset')->once()->with($assetMock)->andReturn($oneAssetInVen);

        // WalletRepository
        $walletRepoMock = Mockery::mock('\Hub\UltraCore\Repository\WalletRepository');

        $sut = new DefaultWalletHandler($venRepoMock, $assetRepoMock, $walletRepoMock);
        $sut->purchase($testUserId, $assetMock, $testAssetBuyAmount);
    }
}
