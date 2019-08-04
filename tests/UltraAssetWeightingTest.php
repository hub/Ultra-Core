<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 18-02-2018
 */

namespace Hub\UltraCore;

use PHPUnit\Framework\TestCase;

class UltraAssetWeightingTest extends TestCase
{
    /**
     * @test
     * @dataProvider provideConditions
     * @param array $data
     * @param bool $expectedIsApproved
     */
    public function shouldReturnInstantiatedValuesAllTheTime(array $data)
    {
        $assetWeighting = new UltraAssetWeighting(
            $data['currencyName'],
            $data['currencyAmount'],
            $data['percentage']
        );

        $this->assertSame($data['currencyName'], $assetWeighting->currencyName());
        $this->assertSame($data['currencyAmount'], $assetWeighting->currencyAmount());
        $this->assertSame($data['percentage'], $assetWeighting->percentage());
        $this->assertSame($data['percentage_amount'], $assetWeighting->percentageAmount());
        $this->assertSame($data['array'], $assetWeighting->toArray());
    }

    /**
     * @return array
     */
    public function provideConditions()
    {
        return array(
            array(
                array(
                    'currencyName' => 'ETH',
                    'currencyAmount' => 0.5,
                    'percentage' => 50.0, // 50%
                    'percentage_amount' => 0.25, // 50% of 0.5
                    'array' => array(
                        'currency_name' => 'ETH',
                        'currency_amount' => 0.5,
                        'percentage' => 50.0,
                        'percentage_amount' => 0.25,
                    ),
                ),
            ),
            array(
                array(
                    'currencyName' => 'BTC',
                    'currencyAmount' => 2.0,
                    'percentage' => 10.0, // 10%
                    'percentage_amount' => 0.20, // 10% of 2
                    'array' => array(
                        'currency_name' => 'BTC',
                        'currency_amount' => 2.0,
                        'percentage' => 10.0,
                        'percentage_amount' => 0.20,
                    ),
                ),
            ),
        );
    }
}
