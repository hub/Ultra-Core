<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Asset;

use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\TestCase;

/**
 * Class FirstIssuerFirstServedIssuerSelectionStrategyTest
 * @package Hub\UltraCore\Asset
 */
class FirstIssuerFirstServedIssuerSelectionStrategyTest extends TestCase
{
    /** @var FirstIssuerFirstServedIssuerSelectionStrategy */
    private $sut;

    /** @var \mysqli|MockInterface */
    private $mysqliMock;

    public function setUp()
    {
        $mysqliResultMock = Mockery::mock('\mysqli_result');
        $mysqliResultMock
            ->shouldReceive('fetch_assoc')
            ->andReturnValues(array(
                array('user_id' => 1, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 50),
                array('user_id' => 2, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 20),
                array('user_id' => 3, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 30),
                array('user_id' => 4, 'original_quantity_issued' => 100, 'remaining_asset_quantity' => 100),
            ));

        $this->mysqliMock = Mockery::mock('\mysqli');
        $this->mysqliMock
            ->shouldReceive('query')
            ->once()
            ->andReturn($mysqliResultMock);

        $this->sut = new FirstIssuerFirstServedIssuerSelectionStrategy($this->mysqliMock);
    }

    /**
     * @test
     */
    public function shouldDeductFromAllIssuersAsTheyHaveAvailableAssets()
    {
        $ultraAssetMock = Mockery::mock('\Hub\UltraCore\UltraAsset');
        $ultraAssetMock->shouldReceive('id')->once()->andReturn(1);

        $issuers = $this->sut->select($ultraAssetMock, 90);

        $expectedIssuers = array(
            new AssetIssuerAuthority(1, 100, 50.0, 50.0),
            new AssetIssuerAuthority(2, 100, 20.0, 20.0),
            new AssetIssuerAuthority(3, 100, 30.0, 20.0),
        );
        $this->assertEquals($expectedIssuers, $issuers);

        // required quantity of 90 is shared between three issuers according to their availability.
        $this->assertSame(50.0, $issuers[0]->getUsableAssetQuantity());
        $this->assertSame(20.0, $issuers[1]->getUsableAssetQuantity());
        $this->assertSame(20.0, $issuers[2]->getUsableAssetQuantity());
    }
}
