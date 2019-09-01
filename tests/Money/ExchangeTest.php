<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Money;

use Hub\UltraCore\CurrencyRatesProvider;
use Hub\UltraCore\Exchange\VenBaseFiatMoneyRateRepository;
use Mockery;
use PHPUnit\Framework\TestCase;

class ExchangeTest extends TestCase
{
    const USD_FOR_ONE_VEN = 0.0986845216;
    const ULTRA_FOR_ONE_VEN = 0.00003705595;

    /**
     * @var Exchange
     */
    private $sut;

    public function setUp()
    {
        /**
         * @var array currency exchange rates taken by the ven api as of today (2019-08-18).
         * @see http://apilaravel.ven.vc/api/ven/exchange
         * @see VenBaseFiatMoneyRateRepository
         */
        $testCurrencies = [
            new CurrencyRate('USD', self::USD_FOR_ONE_VEN),
            new CurrencyRate('uXAB', self::ULTRA_FOR_ONE_VEN),
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
     * Let's get the amount in other currencies for 50 VEN
     * @dataProvider getVenAmount
     *
     * @param float  $venAmount
     * @param string $otherCurrencySymbol
     * @param string $expectedOtherCurrency
     * @param int    $precision
     */
    public function testConversionFromVenToOtherCurrencies(
        $venAmount,
        $otherCurrencySymbol,
        $expectedOtherCurrency,
        $precision
    ) {
        $venMoney = new Money($venAmount, Currency::VEN());

        $actualUsdMoney = $this->sut->convertFromVenToOther($venMoney, Currency::USD());

        $this->assertEquals($expectedOtherCurrency, $actualUsdMoney->getAmountAsString($precision));
        $this->assertSame($otherCurrencySymbol, (string)$actualUsdMoney->getCurrency());
    }

    /**
     * @return array
     */
    public function getVenAmount()
    {
        return [
            // 1 Ven = 0.0986845216 USD
            'one-ven-should-match-the-rating' => [1, 'USD', self::USD_FOR_ONE_VEN, 10 /*precision*/],
            // 2 Ven = 0.1973690432 USD
            'two-ven' => [2, 'USD', self::USD_FOR_ONE_VEN * 2, 10],
            // 50 Ven = 4.93 USD
            'fifty-ven' => [50, 'USD', 4.93, 2],
        ];
    }
}
