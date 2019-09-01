<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Exchange;

use Hub\UltraCore\Money\Currency;
use Jenssegers\Mongodb\Connection;

/**
 * This returns the base fiat currency pairs with the exchange rate for 1 Ven.
 * ex: 1 Ven = 0.074015298841854 GBP
 *
 * @package Hub\UltraCore\Exchange
 */
class VenBaseFiatMoneyRateRepository implements BaseFiatMoneyRateRepository
{
    /**
     * @var array
     */
    private $cache;

    /**
     * @var Connection
     */
    private $dbConnection;

    /**
     * @var string
     */
    private $collectionName;

    /**
     * VenExchangeRateRepository constructor.
     *
     * @param Connection $dbConnection   A mongo db connection.
     * @param string     $collectionName Name of the collection where the currency rates are held.
     */
    public function __construct(Connection $dbConnection, $collectionName)
    {
        $this->dbConnection = $dbConnection;
        $this->collectionName = $collectionName;
    }

    /**
     * This returns the very base currencies with the exchange rate against 1 Ven.
     *
     * @return BaseFiatMoney[]
     */
    public function getBaseFiatMoney()
    {
        if (!empty($this->cache)) {
            return $this->cache;
        }

        $exchangeRates = $this->dbConnection->collection($this->collectionName)->get();

        $currencies = [];
        foreach ($exchangeRates as $exchangeRate) {
            $updated = explode(' ', $exchangeRate['updated']);
            $currencies[$exchangeRate['short']] = new BaseFiatMoney(
                $exchangeRate['rate'],
                Currency::custom($exchangeRate['short']),
                $exchangeRate['currency'],
                $updated[1]
            );
        }

        $this->cache = $currencies;

        return $currencies;
    }
}
