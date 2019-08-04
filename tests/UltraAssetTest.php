<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 27/06/2018
 */

namespace Hub\UltraCore;

use PHPUnit\Framework\TestCase;

class UltraAssetTest extends TestCase
{
    /**
     * @test
     */
    public function shouldReturnInstantiatedValuesWithOneWeighting()
    {
        $testWeighting = new UltraAssetWeighting('currencyName', 23.123456, 100);
        $ultraAssetData = array(
            'id' => 1,
            'weightingHash' => 'weightingHash',
            'title' => 'title1',
            'tickerSymbol' => 'tickerSymbol1',
            'numAssets' => 234.67,
            'backgroundImage' => 'backgroundImage',
            'isApproved' => true,
            'isFeatured' => false,
            'authorityUserId' => 14795,
            'weightings' => array($testWeighting),
        );
        $sut = new UltraAsset(
            $ultraAssetData['id'],
            $ultraAssetData['weightingHash'],
            $ultraAssetData['title'],
            $ultraAssetData['tickerSymbol'],
            $ultraAssetData['numAssets'],
            $ultraAssetData['backgroundImage'],
            $ultraAssetData['isApproved'],
            $ultraAssetData['isFeatured'],
            $ultraAssetData['authorityUserId'],
            $ultraAssetData['weightings']
        );

        $this->assertSame($ultraAssetData['id'], $sut->id());
        $this->assertSame($ultraAssetData['weightingHash'], $sut->weightingHash());
        $this->assertSame($ultraAssetData['title'], $sut->title());
        $this->assertSame($ultraAssetData['tickerSymbol'], $sut->tickerSymbol());
        $this->assertSame($ultraAssetData['numAssets'], $sut->numAssets());
        $this->assertSame($ultraAssetData['backgroundImage'], $sut->backgroundImage());
        $this->assertSame($ultraAssetData['isApproved'], $sut->isApproved());
        $this->assertSame($ultraAssetData['isFeatured'], $sut->isFeatured());
        $this->assertSame($ultraAssetData['authorityUserId'], $sut->authorityUserId());
        $this->assertSame($ultraAssetData['weightings'], $sut->weightings());
        $this->assertSame(true, $sut->isWithOneWeighting());
        $this->assertSame($testWeighting, $sut->getAssetWeightingByPercentage());
    }

    /**
     * @test
     */
    public function shouldReturnCorrectWeightingData()
    {
        $testWeighting1 = new UltraAssetWeighting('currencyName1', 11.123456, 80);
        $testWeighting2 = new UltraAssetWeighting('currencyName2', 22.123456, 20);
        $sut = new UltraAsset(
            1,
            'weightingHash',
            'title',
            'tickerSymbol',
            'numAssets',
            'backgroundImage',
            'isApproved',
            'isFeatured',
            'authorityUserId',
            array($testWeighting1, $testWeighting2)
        );

        $this->assertSame(false, $sut->isWithOneWeighting());
        $this->assertSame($testWeighting1, $sut->getAssetWeightingByPercentage(80));
        $this->assertSame($testWeighting2, $sut->getAssetWeightingByPercentage(20));
        $this->assertNull($sut->getAssetWeightingByPercentage()); // no base currency with 100% weighting
    }
}
