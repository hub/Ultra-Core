<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Exchange;

use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Money;

class BaseFiatMoney extends Money
{
    /**
     * @var string
     */
    private $longName;

    /**
     * @var int
     */
    private $rateUpdatedAt;

    /**
     * @var int|null
     */
    private $ultraAssetId;

    /**
     * @var int
     */
    private $ultraAssetComposedCurrencyCount;

    /**
     * BaseFiatMoney constructor.
     *
     * @param float    $amountPerOneVen                 Amount of base fiat money per 1 Ven.
     * @param Currency $currency                        The base fiat currency.
     * @param string   $longName                        Long name of the currency.
     * @param int      $rateUpdatedAt                   Last time the currency rate is updated/
     * @param int|null $ultraAssetId                    [optional] An Ultra asset id if one is associated. Usually
     *                                                  ultra assets are composed of one or many fiat currencies. In
     *                                                  case of a one 100% fiat currency composition. The id of that
     *                                                  particular ultra assets must be set here to relate it.
     * @param int      $ultraAssetComposedCurrencyCount [optional] If an ultra asset is associated then the number of
     *                                                  fiat currencies that this base currency is composed of. In this
     *                                                  case, this fiat currency is most likely an Ultra Asset that we
     *                                                  classed as a fiat currency within our exchange.
     */
    public function __construct(
        $amountPerOneVen,
        Currency $currency,
        $longName,
        $rateUpdatedAt,
        $ultraAssetId = null,
        $ultraAssetComposedCurrencyCount = 0
    ) {
        parent::__construct($amountPerOneVen, $currency);
        $this->longName = $longName;
        $this->rateUpdatedAt = $rateUpdatedAt;
        $this->ultraAssetId = $ultraAssetId;
        $this->ultraAssetComposedCurrencyCount = $ultraAssetComposedCurrencyCount;
    }

    /**
     * @return string
     */
    public function getLongName()
    {
        return $this->longName;
    }

    /**
     * @return int
     */
    public function getRateUpdatedAt()
    {
        return $this->rateUpdatedAt;
    }

    /**
     * @return int|null
     */
    public function getUltraAssetId()
    {
        return $this->ultraAssetId;
    }

    /**
     * @param int $ultraAssetId
     */
    public function setUltraAssetId($ultraAssetId)
    {
        $this->ultraAssetId = $ultraAssetId;
    }

    /**
     * @return int
     */
    public function getUltraAssetComposedCurrencyCount()
    {
        return $this->ultraAssetComposedCurrencyCount;
    }

    /**
     * @param int $ultraAssetComposedCurrencyCount
     */
    public function setUltraAssetComposedCurrencyCount($ultraAssetComposedCurrencyCount)
    {
        $this->ultraAssetComposedCurrencyCount = $ultraAssetComposedCurrencyCount;
    }

    /**
     * This returns true if this money has got a sub money composition of more than 1 currency.
     * If that is the case, this is an Ultra Asset that we class as a fiat currency within our exchange.
     *
     * @return bool
     */
    public function isUltraFiatCurrency()
    {
        return $this->getUltraAssetComposedCurrencyCount() > 1;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'currency' => $this->getLongName(),
            'rate' => $this->getAmount(),
            'short' => $this->getCurrency()->getStringRepresentation(),
            'is_manual' => false, // TODO: Not sure what this mean. remove this after asking legacy developers.
            'ticker_symbol' => $this->getCurrency()->getStringRepresentation(),
            'updated' => [
                'sec' => intval($this->getRateUpdatedAt()),
                'usec' => 0, // TODO: remove this array and just return the uts. see if we really use this somewhere
            ],
            'meta' => [
                'ultra_asset_id' => $this->getUltraAssetId(),
                'ultra_num_weighting' => $this->getUltraAssetComposedCurrencyCount(),
            ],
        ];
    }
}
