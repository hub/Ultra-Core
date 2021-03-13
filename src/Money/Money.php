<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Money;

class Money
{
    /**
     * @var float
     */
    private $amount;

    /**
     * @var Currency
     */
    private $currency;

    /**
     * Money constructor.
     *
     * @param float    $amount   Amount of money
     * @param Currency $currency The currency of the money
     */
    public function __construct($amount, Currency $currency)
    {
        $this->amount = floatval($amount);
        $this->currency = $currency;
    }

    /**
     * @return float
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * Use this to get the string representation of the current amount.
     *
     * @param int $precision Number of decimal places to be used.
     *
     * @return string
     */
    public function getAmountAsString($precision = 4)
    {
        return number_format($this->amount, $precision, '.', '');
    }

    /**
     * @return Currency
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * Use this to get the multiplied amount of money by a given rate.
     *
     * @param float $multiplier currency rate
     *
     * @return Money
     */
    public function multiplyBy($multiplier)
    {
        return new self($this->getAmount() * $multiplier, $this->getCurrency());
    }

    /**
     * Use this to get the divided amount of money by a given rate.
     *
     * @param float $divisionValue currency rate
     *
     * @return Money
     */
    public function divideBy($divisionValue)
    {
        if ($divisionValue == 0) {
            return new self($this->getAmount(), $this->getCurrency());
        }

        return new self($this->getAmount() / $divisionValue, $this->getCurrency());
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getAmountAsString(10);
    }
}
