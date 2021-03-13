<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Money;

class CurrencyRate
{
    /**
     * @var string
     */
    private $currencyName;

    /**
     * @var float
     */
    private $ratePerOneVen;

    /**
     * CurrencyRate constructor.
     *
     * @param string $currencyName  Ticker name of the secondary currency.
     *                              Secondary means always with respect to 1 Ven.
     *                              ex: USD, uUSD, GBP
     * @param float  $ratePerOneVen Currency amount per one Ven.
     */
    public function __construct($currencyName, $ratePerOneVen)
    {
        $this->currencyName = $currencyName;
        $this->ratePerOneVen = $ratePerOneVen;
    }

    /**
     * @return string
     */
    public function getCurrencyName()
    {
        return $this->currencyName;
    }

    /**
     * @return float
     */
    public function getRatePerOneVen()
    {
        return $this->ratePerOneVen;
    }
}
