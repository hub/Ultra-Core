<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 01-07-2018
 */

namespace Hub\UltraCore;

use PHPUnit\Framework\TestCase;

class UltraAssetFactoryTest extends TestCase
{
    /**
     * @test
     */
    public function shouldCreateUltraAssetObjectWithAllTheWeightings()
    {
        $testUltraAssetRawData = array(
            'id' => 1,
            'hash' => 'testHash',
            'title' => 'testTitle',
            'ticker_symbol' => 'testTickerSymbol',
            'num_assets' => 11.0,
            'background_image' => 'testBackgroundImage',
            'is_approved' => 1,
            'is_featured' => 0,
            'user_id' => 18495,
            'weightings' => '[{"type":"testBaseCurrencyTicker","amount":100}]',
        );

        $actualAssetObject = UltraAssetFactory::fromArray($testUltraAssetRawData);

        $this->assertSame(true, $actualAssetObject->isWithOneWeighting());
        $this->assertSame($testUltraAssetRawData['id'], $actualAssetObject->id());
        $this->assertSame($testUltraAssetRawData['hash'], $actualAssetObject->weightingHash());
        $this->assertSame($testUltraAssetRawData['title'], $actualAssetObject->title());
        $this->assertSame($testUltraAssetRawData['ticker_symbol'], $actualAssetObject->tickerSymbol());
        $this->assertSame($testUltraAssetRawData['num_assets'], $actualAssetObject->numAssets());
        $this->assertSame($testUltraAssetRawData['background_image'], $actualAssetObject->backgroundImage());
        $this->assertSame(boolval($testUltraAssetRawData['is_approved']), $actualAssetObject->isApproved());
        $this->assertSame(boolval($testUltraAssetRawData['is_featured']), $actualAssetObject->isFeatured());
        $this->assertSame($testUltraAssetRawData['user_id'], $actualAssetObject->authorityUserId());
        $this->assertSame('testBaseCurrencyTicker', $actualAssetObject->weightings()[0]->currencyName());
        $this->assertSame(0, $actualAssetObject->weightings()[0]->currencyAmount());
        $this->assertSame(100, $actualAssetObject->weightings()[0]->percentage());
    }
}
