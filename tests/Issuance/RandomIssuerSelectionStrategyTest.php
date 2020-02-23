<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Issuance;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

class RandomIssuerSelectionStrategyTest extends TestCase
{
    const TEST_BUY_AMOUNT = 90;

    /**
     * @test
     */
    public function shouldUseTheInnerStrategyWhenWeDoNotHaveOneIssuerWhoCanSupplyTheRequestedQuantity()
    {
        $expectedIssuers = array(array('expected'), array('issuers'));
        $testUltraAssetId = 1;
        $issuerSelectionStrategyMock = Mockery::mock('\Hub\UltraCore\Issuance\FirstIssuerFirstServedIssuerSelectionStrategy');
        $issuerSelectionStrategyMock->expects('select')
            ->once()
            ->andReturn($expectedIssuers);

        $sut = new RandomIssuerSelectionStrategy($this->getMySqlMockWhichReturnNoData(), $issuerSelectionStrategyMock);
        $issuers = $sut->select($testUltraAssetId, self::TEST_BUY_AMOUNT);

        $this->assertEquals($expectedIssuers, $issuers);
    }

    /**
     * @test
     */
    public function shouldSendTheFirstIssuerWeFoundWhoHasGotSupplyForTheRequestedQuantity()
    {
        $expectedIssuer = new AssetIssuerAuthority(1, 100, 91, 90);
        $ultraAssetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $ultraAssetMock->shouldReceive('id')->once()->andReturn(1);
        $issuerSelectionStrategyMock = Mockery::mock('\Hub\UltraCore\Issuance\FirstIssuerFirstServedIssuerSelectionStrategy');
        $issuerSelectionStrategyMock->expects('select')->never();

        $mysqlMock = $this->getMySqlMock(array(
            array(
                'user_id' => $expectedIssuer->getAuthorityUserId(),
                'original_quantity_issued' => $expectedIssuer->getOriginalQuantityIssued(),
                'remaining_asset_quantity' => $expectedIssuer->getRemainingAssetQuantity(),
            ),
            null,
        ));
        $sut = new RandomIssuerSelectionStrategy($mysqlMock, $issuerSelectionStrategyMock);
        $issuers = $sut->select($ultraAssetMock, self::TEST_BUY_AMOUNT);

        $this->assertEquals(array($expectedIssuer), $issuers);
    }

    /**
     * @return Mockery\LegacyMockInterface|MockInterface|\mysqli
     */
    private function getMySqlMockWhichReturnNoData()
    {
        $mysqliResultMock = Mockery::mock('\mysqli_result');
        $mysqliResultMock
            ->shouldReceive('fetch_assoc')
            ->andReturn(null);

        $mysqliMock = Mockery::mock('\mysqli');
        $mysqliMock
            ->shouldReceive('query')
            ->once()
            ->andReturn($mysqliResultMock);

        return $mysqliMock;
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