<?php

namespace Hub\UltraCore\Exchange;

use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Money;
use Hub\UltraCore\UltraAsset;
use Hub\UltraCore\UltraAssetsRepository;
use Hub\UltraCore\UltraAssetWeighting;
use Mockery;
use PHPUnit\Framework\TestCase;

class UltraEnrichingVenBaseFiatMoneyRateRepositoryTest extends TestCase
{
    private function asset($id, $ticker, $title, $currency = 'USD', $rate = 0.0985)
    {
        return new UltraAsset($id, 'hash', $title, '', 'stablecoin', $ticker, 1000, '', '', 1, 0, 3,
            UltraAssetsRepository::TYPE_CURRENCY_COMBO, array(new UltraAssetWeighting($currency, $rate, 100)), null, null, '2026-11-16');
    }


    #[\PHPUnit\Framework\Attributes\Test]
    public function usdIsLinkedToUusdEvenWhenAnotherAssetIsAllUsd()
    {
        // 16 Nov 2026: Dropp (#141, basket "USD 100") was approved after uUSD (#2) and took over USD
        $inner = Mockery::mock(VenBaseFiatMoneyRateRepository::class);
        $inner->shouldReceive('getBaseFiatMoney')->andReturn(array(
            'USD' => new BaseFiatMoney(0.0985, Currency::USD(), 'US Dollar', time()),
        ));
        $assets = Mockery::mock(UltraAssetsRepository::class);
        $assets->shouldReceive('getAllActiveAssets')->andReturn(array(
            $this->asset(2, 'uUSD', 'US Dollar'),
            $this->asset(141, 'uDRPP', 'Dropp'),
        ));
        $assets->shouldReceive('getAssetAmountForOneVen')->andReturn(new Money(0.0985, Currency::custom('uDRPP')));

        $rates = (new UltraEnrichingVenBaseFiatMoneyRateRepository($inner, $assets))->getBaseFiatMoney();

        $this->assertSame(2, $rates['USD']->getUltraAssetId());
        // Dropp is still listed, under its own ticker
        $this->assertSame(141, $rates['uDRPP']->getUltraAssetId());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function carbonIsLinkedToUcrb()
    {
        // CARBON's own asset is uCRB (Ven Carbon, #19), not "uCARBON"
        $inner = Mockery::mock(VenBaseFiatMoneyRateRepository::class);
        $inner->shouldReceive('getBaseFiatMoney')->andReturn(array(
            'CARBON' => new BaseFiatMoney(0.7105, Currency::custom('CARBON'), 'Carbon ICE EUA', time()),
        ));
        $assets = Mockery::mock(UltraAssetsRepository::class);
        $assets->shouldReceive('getAllActiveAssets')->andReturn(array(
            $this->asset(19, 'uCRB', 'Ven Carbon', 'CARBON', 0.7105),
        ));
        $assets->shouldReceive('getAssetAmountForOneVen')->andReturn(new Money(0.7105, Currency::custom('uCRB')));

        $rates = (new UltraEnrichingVenBaseFiatMoneyRateRepository($inner, $assets))->getBaseFiatMoney();

        $this->assertSame(19, $rates['CARBON']->getUltraAssetId());
        $this->assertArrayNotHasKey('uCRB', $rates);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function onlyListedExceptionsSkipTheTickerRule()
    {
        $this->assertTrue(UltraEnrichingVenBaseFiatMoneyRateRepository::isOwnAsset('USD', 'uUSD'));
        $this->assertTrue(UltraEnrichingVenBaseFiatMoneyRateRepository::isOwnAsset('carbon', 'ucrb'));
        $this->assertFalse(UltraEnrichingVenBaseFiatMoneyRateRepository::isOwnAsset('USD', 'uDRPP'));
        $this->assertFalse(UltraEnrichingVenBaseFiatMoneyRateRepository::isOwnAsset('CARBON', 'uCARBON'));
        $this->assertFalse(UltraEnrichingVenBaseFiatMoneyRateRepository::isOwnAsset('EUR', 'uCRB'));
    }
}
