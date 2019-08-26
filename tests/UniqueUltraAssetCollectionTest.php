<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 27/06/2018
 */

namespace Hub\UltraCore;

use PHPUnit\Framework\TestCase;

class UniqueUltraAssetCollectionTest extends TestCase
{
    /**
     * @test
     */
    public function shouldReturnUniqueAssetsWhenAddedAssetWithSimilarWeightings()
    {
        $sut = new UniqueUltraAssetCollection();

        $asset1Weighting = new UltraAssetWeighting('currencyName1', 11.123456, 100);
        $sut->addAsset(
            $testAsset1 = new UltraAsset(
                1,
                md5(sprintf('[{"type":"%s","amount":%s}]', $asset1Weighting->currencyName(), $asset1Weighting->percentage())),
                'title1',
                'category1',
                'tickerSymbol1',
                6,
                'backgroundImage1',
                'iconImage1',
                true,
                true,
                1,
                'weightingType1',
                array($asset1Weighting),
                'created_at1'
            )
        );

        $asset2Weighting = $asset1Weighting;
        $sut->addAsset(
            $testAsset2 = new UltraAsset(
                2,
                md5(sprintf('[{"type":"%s","amount":%s}]', $asset2Weighting->currencyName(), $asset2Weighting->percentage())),
                'title2',
                'category2',
                'tickerSymbol2',
                4,
                'backgroundImage2',
                'iconImage2',
                true,
                true,
                1,
                'weightingType2',
                array($asset2Weighting),
                'created_at2'
            )
        );
        $sut->addAsset(
            $testAsset3 = new UltraAsset(
                3,
                'weightingHash3',
                'title3',
                'category3',
                'tickerSymbol3',
                0,
                'backgroundImage3',
                'iconImage3',
                true,
                true,
                1,
                'weightingType3',
                array(new UltraAssetWeighting('currencyName3', 33.123456, 100)),
                'created_at3'
            )
        );

        // please note that we expect only two assets as two of them are duplicates with same weighting config
        $this->assertEquals(
            array(
                $testAsset1->weightingHash() => new UltraAsset(
                    1,
                    $testAsset1->weightingHash(),
                    $asset1Weighting->currencyName(),
                    'category1',
                    sprintf('u%s', strtoupper($asset1Weighting->currencyName())),
                    10, // 6 + 4 as the asset 1 and asset 2 has similar weighting config. both got 100% 'currencyName1' currency
                    'backgroundImage1',
                    'iconImage1',
                    true,
                    true,
                    1,
                    'weightingType1',
                    array($asset1Weighting),
                    'created_at1'
                ),
                $testAsset3->weightingHash() => $testAsset3,
            ),
            $sut->getAssets()
        );
    }
}
