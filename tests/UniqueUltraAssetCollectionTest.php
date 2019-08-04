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
                'tickerSymbol1',
                6,
                'backgroundImage1',
                true,
                true,
                1,
                array($asset1Weighting)
            )
        );

        $asset2Weighting = $asset1Weighting;
        $sut->addAsset(
            $testAsset2 = new UltraAsset(
                2,
                md5(sprintf('[{"type":"%s","amount":%s}]', $asset2Weighting->currencyName(), $asset2Weighting->percentage())),
                'title2',
                'tickerSymbol2',
                4,
                'backgroundImage2',
                true,
                true,
                1,
                array($asset2Weighting)
            )
        );
        $sut->addAsset(
            $testAsset3 = new UltraAsset(
                3,
                'weightingHash3',
                'title3',
                'tickerSymbol3',
                0,
                'backgroundImage3',
                true,
                true,
                1,
                array(new UltraAssetWeighting('currencyName3', 33.123456, 100))
            )
        );

        // please note that we expect only two assets as two of them are duplicates with same weighting config
        $this->assertEquals(
            array(
                $testAsset1->weightingHash() => new UltraAsset(
                    1,
                    $testAsset1->weightingHash(),
                    $asset1Weighting->currencyName(),
                    sprintf('u%s', strtoupper($asset1Weighting->currencyName())),
                    10, // 6 + 4 as the asset 1 and asset 2 has similar weighting config. both got 100% 'currencyName1' currency
                    'backgroundImage1',
                    true,
                    true,
                    1,
                    array($asset1Weighting)
                ),
                $testAsset3->weightingHash() => $testAsset3,
            ),
            $sut->getAssets()
        );
    }
}
