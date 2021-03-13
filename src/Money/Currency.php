<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Money;

class Currency
{
    /**
     * @var int
     */
    private $percentFactor;

    /**
     * @var string
     */
    private $stringRepresentation;

    /**
     * Currency constructor.
     *
     * @param int    $percentFactor
     * @param string $stringRepresentation Currency short label.
     *                                     ex: GBP, USD
     */
    private function __construct($percentFactor, $stringRepresentation)
    {
        $this->percentFactor = $percentFactor;
        $this->stringRepresentation = $stringRepresentation;
    }

    /**
     * @return string
     */
    public function getPercentFactor()
    {
        return $this->percentFactor;
    }

    /**
     * @return string
     */
    public function getStringRepresentation()
    {
        return $this->stringRepresentation;
    }

    /**
     * @return Currency
     */
    public static function VEN()
    {
        return new self(100, 'VEN');
    }

    /**
     * @return Currency
     */
    public static function EOS()
    {
        return new self(100, 'EOS');
    }

    /**
     * @return Currency
     */
    public static function USD()
    {
        return new self(100, 'USD');
    }

    /**
     * @param string $customCurrencySymbol Custom asset symbol
     *
     * @return Currency
     */
    public static function custom($customCurrencySymbol)
    {
        return new self(100, $customCurrencySymbol);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->getStringRepresentation();
    }
}
