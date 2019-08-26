<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Money;

use Hub\UltraCore\CurrencyRatesProvider;
use Mockery;
use PHPUnit\Framework\TestCase;

class ExchangeTest extends TestCase
{
    /**
     * @var Exchange
     */
    private $sut;

    public function setUp()
    {
        /**
         * @var array currency exchange rates taken by the ven api as of today (2019-08-18).
         * @see http://apilaravel.ven.vc/api/ven/exchange
         */
        $testCurrencies = [
            new CurrencyRate('USD', 0.0986845216),
            new CurrencyRate('uXAB', 0.00003705595),
        ];

        $currencyRatesProviderMock = Mockery::mock(CurrencyRatesProvider::class);
        $currencyRatesProviderMock
            ->shouldReceive('getByPrimaryCurrencySymbol')
            ->once()
            ->andReturn($testCurrencies);

        $this->sut = new Exchange($currencyRatesProviderMock);
    }

    /**
     * Let's get the amount in VEN for 1 USD
     * @dataProvider getOtherCurrencies
     *
     * @param Money  $fromOtherMoney
     * @param string $expectedVenAmount
     */
    public function testConversionToVen(Money $fromOtherMoney, $expectedVenAmount)
    {
        $actualVenMoney = $this->sut->convertToVen($fromOtherMoney);

        $this->assertEquals($expectedVenAmount, $actualVenMoney->getAmountAsString());
        $this->assertSame('VEN', (string)$actualVenMoney->getCurrency());
    }

    /**
     * @return array
     */
    public function getOtherCurrencies()
    {
        return [
            // Let's get the amount in VEN for 1 USD
            'existing-usd' => [new Money(1, Currency::USD()), '10.1333'],
            // Let's get the amount in VEN for half of a uXAB
            'existing-ultra' => [new Money(0.5, Currency::custom('uXAB')), '13493.1097'],
            // since the rates an not available, it should return the same money
            'non-existing-ultra' => [new Money(15, Currency::custom('INVALID')), '15.0000'],
        ];
    }

    /**
     * Let's get the amount in USD for 50 VEN
     */
    public function testConversionFromVen()
    {
        $venMoney = new Money(50, Currency::VEN());

        $actualUsdMoney = $this->sut->convertFromVenToOther($venMoney, Currency::USD());

        $this->assertEquals('4.9342', $actualUsdMoney->getAmountAsString());
        $this->assertSame('USD', (string)$actualUsdMoney->getCurrency());
    }
}
