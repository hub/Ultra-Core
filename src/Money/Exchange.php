<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Money;

use Hub\UltraCore\CurrencyRatesProviderInterface;

/**
 * This exchange class can be used to convert:
 *
 * - Ven to Ultra
 * - Ultra to Ven
 * - Ultra to Ultra
 *
 * Ultra is the name that we give for currencies/assets that we have created using one or more base fiat currencies.
 *
 * @package Hub\UltraCore\Money
 */
class Exchange
{
    /**
     * @var CurrencyRatesProviderInterface
     */
    private $currencyRatesProvider;

    /**
     * Exchange constructor.
     *
     * @param CurrencyRatesProviderInterface $currencyRatesProvider
     */
    public function __construct(CurrencyRatesProviderInterface $currencyRatesProvider)
    {
        $this->currencyRatesProvider = $currencyRatesProvider;
    }

    /**
     * Use this to convert between Ultra currencies.
     * Both source and destination currencies must be non VEN type ones.
     *
     * @param Money $fromUltra Ultra currency money that you want to convert from.
     * @param Money $toUltra   Destination Ultra currency.
     *
     * @return Money|null returns null if no ultra currency passed.
     */
    public function convertFromUltraToUltra(Money $fromUltra, Money $toUltra)
    {
        if ($fromUltra->getCurrency()->getStringRepresentation() === Currency::VEN()
            || $toUltra->getCurrency()->getStringRepresentation() === Currency::VEN()
        ) {
            return null;
        }

        $fromInVenMoney = $this->convertToVen($fromUltra);

        return $this->convertFromVenToOther($fromInVenMoney, $toUltra->getCurrency());
    }

    /**
     * Use this to convert any currency amount to VEN.
     * Ex:
     * If 1 VEN = 2 GBP(rate for 1 ven) as the rate to VEN.
     * Then a given other currency of 1 GBP = 0.5 VEN
     *
     * @param Money $otherMoney The money type that you want to convert to Ven
     *
     * @return Money
     */
    public function convertToVen(Money $otherMoney)
    {
        if ($otherMoney->getCurrency()->getStringRepresentation() !== Currency::VEN()->getStringRepresentation()) {
            return new Money(
                $otherMoney->divideBy($this->getRateForOneVen($otherMoney->getCurrency()))->getAmount(),
                Currency::VEN()
            );
        }

        return $otherMoney;
    }

    /**
     * Use this to convert any amount of VEN to another currency amount.
     * Ex:
     * If 1 VEN = 2 GBP(rate for 1 ven) as the rate to VEN.
     * Then a given amount of 10 VEN = 20 GBP
     *
     * @param Money    $venMoney   Amount of VEN money.
     * @param Currency $toCurrency The destination currency.
     *
     * @return Money
     */
    public function convertFromVenToOther(Money $venMoney, Currency $toCurrency)
    {
        if ($venMoney->getCurrency()->getStringRepresentation() === Currency::VEN()->getStringRepresentation()) {
            return new Money($venMoney->multiplyBy($this->getRateForOneVen($toCurrency))->getAmount(), $toCurrency);
        }

        return $venMoney;
    }

    /**
     * @param Currency $fromCurrency
     *
     * @return float
     */
    private function getRateForOneVen(Currency $fromCurrency)
    {
        $currencies = $this->currencyRatesProvider->getByPrimaryCurrencySymbol(Currency::VEN());
        $fromCurrencyTicker = strtolower($fromCurrency->getStringRepresentation());
        $search = [$fromCurrencyTicker, 'u' . $fromCurrencyTicker, preg_replace('/^u/', '', $fromCurrencyTicker)];
        foreach ($currencies as $currency) {
            if (!in_array(strtolower($currency->getCurrencyName()), $search)) {
                continue;
            }

            return $currency->getRatePerOneVen();
        }

        return 1;
    }
}
