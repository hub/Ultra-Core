<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 09-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Money\CurrencyRate;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;
use Mockery;

class UltraAssetsRepositoryTest extends TestCase
{
    /** @var array */
    private $testCurrencies = [
        ['secondary_currency' => 'XAU', 'current_amount' => 0.0000762543],
        ['secondary_currency' => 'ETH', 'current_amount' => 0.0001658963],
        ['secondary_currency' => 'CAD', 'current_amount' => 0.1262628972],
    ];

    /** @var UltraAssetsRepository */
    private $sut;

    /** @var \mysqli|MockInterface */
    private $mysqliMock;

    public function setUp()
    {
        $testCurrencyRates = [];
        foreach ($this->testCurrencies as $testCurrency) {
            $testCurrencyRates[] = new CurrencyRate($testCurrency['secondary_currency'], $testCurrency['current_amount']);
        }
        $this->mysqliMock = Mockery::mock('\mysqli');
        $currencyRatesProviderMock = Mockery::mock('\Hub\UltraCore\CurrencyRatesProvider');
        $currencyRatesProviderMock
            ->shouldReceive('getByPrimaryCurrencySymbol')
            ->once()
            ->andReturn($testCurrencyRates);

        $this->sut = new UltraAssetsRepository($this->mysqliMock, $currencyRatesProviderMock);
    }

    /**
     * @test
     */
    public function shouldReturnTheCorrectAssetAmountUsingAllWeightingConfig()
    {
        $testWeightingsConfig = [
            new UltraAssetWeighting('CAD', 0, 37),
            new UltraAssetWeighting('ETH', 0, 13),
            new UltraAssetWeighting('XAU', 0, 50),
        ];

        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('weightingType')->once();
        $assetMock->shouldReceive('weightings')->once()->andReturn($testWeightingsConfig);
        $expectedWeightings = [
            new UltraAssetWeighting($testWeightingsConfig[0]->currencyName(),
                $this->testCurrencies[2]['current_amount'], 37),
            new UltraAssetWeighting($testWeightingsConfig[1]->currencyName(),
                $this->testCurrencies[1]['current_amount'], 13),
            new UltraAssetWeighting($testWeightingsConfig[2]->currencyName(),
                $this->testCurrencies[0]['current_amount'], 50),
        ];
        $assetMock->shouldReceive('setWeightings')->once()->with($expectedWeightings);

        $this->sut->enrichAssetWeightingAmounts($assetMock);
    }

    /**
     * @test
     */
    public function shouldNotReturnWeightingWithAInvalidCurrencyWeightingConfig()
    {
        $testWeightingsConfig = [
            new UltraAssetWeighting('INVALID_CAD', 0, 37),
            new UltraAssetWeighting('INVALID_ETH', 0, 13),
            new UltraAssetWeighting('INVALID_XAU', 0, 50),
        ];

        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('weightingType')->once();
        $assetMock->shouldReceive('weightings')->once()->andReturn($testWeightingsConfig);
        $expectedWeightings = []; // since we have requested weightings with wrong / non existing currency types, this is empty

        $assetMock->shouldReceive('setWeightings')->once()->with($expectedWeightings);
        $this->sut->enrichAssetWeightingAmounts($assetMock);
    }

    /**
     * @test
     */
    public function shouldReturnTheTotalValueOfTHeAssetUsingCurrencyWeightings()
    {
        $testWeightingsConfig = [
            new UltraAssetWeighting('CAD', $this->testCurrencies[2]['current_amount'], 37),
            new UltraAssetWeighting('ETH', $this->testCurrencies[1]['current_amount'], 13),
            new UltraAssetWeighting('XAU', $this->testCurrencies[0]['current_amount'], 50),
        ];

        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('weightingType')->once()->andReturn(UltraAssetsRepository::TYPE_CURRENCY_COMBO);
        $assetMock->shouldReceive('tickerSymbol')->once();
        $assetMock->shouldReceive('weightings')->once()->andReturn($testWeightingsConfig);

        $expectedAssetValueInVen = // 0.046776965633000003
            (
                (($this->testCurrencies[2]['current_amount'] /* 0.1262628972 */ / 100) * 37)
                + (($this->testCurrencies[1]['current_amount'] /* 0.0001658963 */ / 100) * 13)
                + (($this->testCurrencies[0]['current_amount'] /* 0.0000762543 */ / 100) * 50)
            );

        // The test asset must be worth 0.0467 VEN
        $actualAssetValue = $this->sut->getAssetAmountForOneVen($assetMock);
        $this->assertSame($expectedAssetValueInVen, $actualAssetValue->getAmount());
        $this->assertTrue((0.046776965633 === $actualAssetValue->getAmount()));
    }

    /**
     * @test
     */
    public function shouldReturnTheTotalInVenForCustomVenAmounts()
    {
        // asset with custom ven amounts always contain one weighting
        $testWeightingsConfig = [new UltraAssetWeighting('Ven', 13, 100)];

        $assetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $assetMock->shouldReceive('weightingType')->once()->andReturn(UltraAssetsRepository::TYPE_EXTERNAL_ENTITY);
        $assetMock->shouldReceive('tickerSymbol')->once();
        $assetMock->shouldReceive('weightings')->once()->andReturn($testWeightingsConfig);

        $actualAssetValue = $this->sut->getAssetAmountForOneVen($assetMock);
        $this->assertSame(13.0, $actualAssetValue->getAmount());
    }

    /**
     * @test
     */
    public function shouldSendQuantitiesFromMultipleButSimilarAssetsWhenBuyingAHugeAssetQuantity()
    {
        $requiredQuantity = 10;
        $ultraAsset1Data = array(
            'id' => 1,
            'hash' => 'similarWeightingHash',
            'title' => 'title1',
            'category' => 'category1',
            'ticker_symbol' => 'tickerSymbol1',
            'num_assets' => 8,
            'background_image' => 'backgroundImage1',
            'icon_image' => 'iconImage1',
            'is_approved' => 1,
            'is_featured' => 1,
            'user_id' => 14795,
            'weighting_type' => 'weightingType',
            'weightings' => '[{"type":"currencyName","amount":100}]',
            'created_at' => '2000-01-01 00:00:00',
        );
        $ultraAsset2Data = array(
            'id' => 1,
            'hash' => $ultraAsset1Data['hash'],
            'title' => 'title2',
            'category' => 'category2',
            'ticker_symbol' => 'tickerSymbol2',
            'num_assets' => 8,
            'background_image' => 'backgroundImage2',
            'icon_image' => 'iconImage2',
            'is_approved' => 1,
            'is_featured' => 1,
            'user_id' => 15795,
            'weighting_type' => 'weightingType',
            'weightings' => $ultraAsset1Data['weightings'],
            'created_at' => $ultraAsset1Data['created_at'],
        );

        $resultMock = Mockery::mock('\mysqli_result');
        $resultMock->shouldReceive('fetch_assoc')->once()->andReturn($ultraAsset1Data);
        $resultMock->shouldReceive('fetch_assoc')->once()->andReturn($ultraAsset2Data);
        $resultMock->shouldReceive('fetch_assoc')->once()->andReturn(null);
        $this->mysqliMock->shouldReceive('query')->andReturn($resultMock);

        $actualQuantitiesPerSimilarAssets = $this->sut->getQuantitiesPerSimilarAsset(
            $testAsset1 = new UltraAsset(
                $ultraAsset1Data['id'],
                $ultraAsset1Data['hash'],
                $ultraAsset1Data['title'],
                $ultraAsset1Data['category'],
                $ultraAsset1Data['ticker_symbol'],
                $ultraAsset1Data['num_assets'],
                $ultraAsset1Data['background_image'],
                $ultraAsset1Data['icon_image'],
                $ultraAsset1Data['is_approved'],
                $ultraAsset1Data['is_featured'],
                $ultraAsset1Data['user_id'],
                $ultraAsset1Data['weighting_type'],
                array(new UltraAssetWeighting('currencyName', 0, 100)),
                $ultraAsset1Data['created_at']
            ),
            $requiredQuantity
        );

        $this->assertEquals(
            [
                [
                    'asset' => $testAsset1,
                    'quantity' => 8.0,
                ],
                [
                    'asset' => new UltraAsset(
                        $ultraAsset2Data['id'],
                        $ultraAsset2Data['hash'],
                        $ultraAsset2Data['title'],
                        $ultraAsset2Data['category'],
                        $ultraAsset2Data['ticker_symbol'],
                        $ultraAsset2Data['num_assets'],
                        $ultraAsset2Data['background_image'],
                        $ultraAsset2Data['icon_image'],
                        $ultraAsset2Data['is_approved'],
                        $ultraAsset2Data['is_featured'],
                        $ultraAsset2Data['user_id'],
                        $ultraAsset2Data['weighting_type'],
                        array(new UltraAssetWeighting('currencyName', 0, 100)),
                        $ultraAsset2Data['created_at']
                    ),
                    'quantity' => 2.0,
                ],
            ],
            $actualQuantitiesPerSimilarAssets
        );
    }

    /**
     * @test
     * @expectedException \Hub\UltraCore\Exception\InsufficientAssetAvailabilityException
     * @expectedExceptionMessage There are no such amount of assets available for your requested amount of 10. Only 9
     *                           available.
     */
    public function shouldThrowExceptionWhenNoSimilarAssetsAvailableForRequiredQuantity()
    {
        $requiredQuantity = 10; // TEN required
        $ultraAsset1Data = array(
            'id' => 1,
            'hash' => 'similarWeightingHash',
            'title' => 'title1',
            'category' => 'category1',
            'ticker_symbol' => 'tickerSymbol1',
            'num_assets' => 8, // EIGHT HERE
            'background_image' => 'backgroundImage1',
            'icon_image' => 'iconImage1',
            'is_approved' => 1,
            'is_featured' => 1,
            'user_id' => 14795,
            'weighting_type' => 'weightingType',
            'weightings' => '[{"type":"currencyName","amount":100}]',
            'created_at' => '2019-01-01',
        );
        $ultraAsset2Data = array(
            'id' => 1,
            'hash' => $ultraAsset1Data['hash'],
            'title' => 'title2',
            'category' => 'category2',
            'ticker_symbol' => 'tickerSymbol2',
            'num_assets' => 1, // ONE HERE
            'background_image' => 'backgroundImage2',
            'icon_image' => 'iconImage2',
            'is_approved' => 1,
            'is_featured' => 1,
            'user_id' => 15795,
            'weighting_type' => 'weightingType',
            'weightings' => $ultraAsset1Data['weightings'],
            'created_at' => $ultraAsset1Data['created_at'],
        );

        $resultMock = Mockery::mock('\mysqli_result');
        $resultMock->shouldReceive('fetch_assoc')->once()->andReturn($ultraAsset1Data);
        $resultMock->shouldReceive('fetch_assoc')->once()->andReturn($ultraAsset2Data);
        $resultMock->shouldReceive('fetch_assoc')->once()->andReturn(null);
        $this->mysqliMock->shouldReceive('query')->andReturn($resultMock);

        $this->sut->getQuantitiesPerSimilarAsset(
            new UltraAsset(
                $ultraAsset1Data['id'],
                $ultraAsset1Data['hash'],
                $ultraAsset1Data['title'],
                $ultraAsset1Data['category'],
                $ultraAsset1Data['ticker_symbol'],
                $ultraAsset1Data['num_assets'],
                $ultraAsset1Data['background_image'],
                $ultraAsset1Data['icon_image'],
                $ultraAsset1Data['is_approved'],
                $ultraAsset1Data['is_featured'],
                $ultraAsset1Data['user_id'],
                $ultraAsset1Data['weighting_type'],
                array(new UltraAssetWeighting('currencyName', 0, 100)),
                $ultraAsset1Data['created_at']
            ),
            $requiredQuantity
        );
    }
}
