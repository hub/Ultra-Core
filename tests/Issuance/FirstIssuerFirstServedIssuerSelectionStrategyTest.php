<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Issuance;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * Class FirstIssuerFirstServedIssuerSelectionStrategyTest
 * @package Hub\UltraCore\Issuance
 */
class FirstIssuerFirstServedIssuerSelectionStrategyTest extends TestCase
{
    const TEST_BUY_AMOUNT = 90;

    /**
     * @test
     * @dataProvider ultraIssuanceDataProvider
     *
     * @param array $testUltraIssuanceRecords
     * @param array $expectedIssuers
     */
    public function shouldDeductFromAllIssuersAsTheyHaveAvailableAssets(
        array $testUltraIssuanceRecords,
        array $expectedIssuers
    ) {
        $ultraAssetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $ultraAssetMock->shouldReceive('id')->once()->andReturn(1);

        $sut = new FirstIssuerFirstServedIssuerSelectionStrategy($this->getMySqlMock($testUltraIssuanceRecords));
        $issuers = $sut->select($ultraAssetMock, self::TEST_BUY_AMOUNT);

        $this->assertEquals($expectedIssuers, $issuers);
    }

    public function ultraIssuanceDataProvider()
    {
        $issuer1 = array('user_id' => 1, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 50);
        $issuer2 = array('user_id' => 2, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 20);
        $issuer3 = array('user_id' => 3, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 30);
        $issuer4 = array('user_id' => 4, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 100);
        $issuer5 = array('user_id' => 5, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 20);

        return array(
            'first_issuer_having_more' => array(
                array($issuer1, $issuer2, $issuer3, $issuer4),
                // required quantity of 90 is shared between three issuers according to their issuance order and remaining amount.
                // 50 + 20 + 20 = 90
                array(
                    new AssetIssuerAuthority(1, 100, 50.0, 50.0),
                    new AssetIssuerAuthority(2, 100, 20.0, 20.0),
                    new AssetIssuerAuthority(3, 100, 30.0, 20.0),
                ),
            ),
            'second_issuer_having_more' => array(
                array($issuer2, $issuer1, $issuer3, $issuer4),
                // 20 + 50 + 20 = 90
                array(
                    new AssetIssuerAuthority(2, 100, 20.0, 20.0),
                    new AssetIssuerAuthority(1, 100, 50.0, 50.0),
                    new AssetIssuerAuthority(3, 100, 30.0, 20.0),
                ),
            ),
            'last_issuer_having_more' => array(
                array($issuer2, $issuer5, $issuer3, $issuer4),
                // 20 + 20 + 30 + 20 = 90
                array(
                    new AssetIssuerAuthority(2, 100, 20.0, 20.0),
                    new AssetIssuerAuthority(5, 100, 20.0, 20.0),
                    new AssetIssuerAuthority(3, 100, 30.0, 30.0),
                    new AssetIssuerAuthority(4, 100, 100.0, 20.0),
                ),
            ),
        );
    }

    /**
     * @param array $testUltraIssuanceRecords
     *
     * @return Mockery\LegacyMockInterface|MockInterface|\mysqli
     */
    private function getMySqlMock(array $testUltraIssuanceRecords)
    {
        $mysqliResultMock = Mockery::mock('\mysqli_result');
        $mysqliResultMock
            ->shouldReceive('fetch_assoc')
            ->andReturnValues($testUltraIssuanceRecords);

        $mysqliMock = Mockery::mock('\mysqli');
        $mysqliMock
            ->shouldReceive('query')
            ->once()
            ->andReturn($mysqliResultMock);

        return $mysqliMock;
    }
}
