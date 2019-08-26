<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 27/06/2018
 */

namespace Hub\UltraCore;

use PHPUnit\Framework\TestCase;

class DefaultUltraAssetCollectionTest extends TestCase
{
    /**
     * @test
     */
    public function shouldBeAbleToRetrieveAddedAssets()
    {
        $sut = new DefaultUltraAssetCollection();
        $sut->addAsset(
            $testAsset = new UltraAsset(
                1,
                'weightingHash',
                'title',
                'category',
                'tickerSymbol',
                'numAssets',
                'backgroundImage',
                'iconImage',
                'isApproved',
                'isFeatured',
                'authorityUserId',
                'weightingType',
                array(new UltraAssetWeighting('currencyName2', 22.123456, 20)),
                '2000-01-01 00:00:00'
            )
        );

        $this->assertEquals(
            array($testAsset->weightingHash() => $testAsset),
            $sut->getAssets()
        );
    }
}
