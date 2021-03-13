<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\CurrencyRate;
use Jenssegers\Mongodb\Connection;

/**
 * This class queries our central Ven currency rates storage which is populated by an external process.
 * @see     https://ven.vc/admin
 *
 * The storage holds the single-source-of-truth for the currency rates between Ven and other base real currencies.
 * This storage stores the amount of base currencies per 1 Ven.
 * ex: 1 Ven = 0.074015298841854 GBP
 *
 * @package Hub\UltraCore
 */
class CentralVenBaseCurrencyRatesProvider implements CurrencyRatesProviderInterface
{
    /**
     * @var CurrencyRate[]
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
     * MongoBasedCurrencyRatesProvider constructor.
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
     * Use this to retrieve currency exchange rates for a given primary currency.
     *
     * @param Currency $primaryCurrency [optional] Primary currency.
     *
     * @return CurrencyRate[]
     */
    public function getByPrimaryCurrencySymbol(Currency $primaryCurrency = null)
    {
        // we don't need to use the passed '$primaryCurrency' as we are querying the central Ven currency rates storage.
        if (!empty($this->cache)) {
            return $this->cache;
        }

        $exchangeRates = $this->dbConnection->collection($this->collectionName)->get();

        $currencies = [];
        foreach ($exchangeRates as $exchangeRate) {
            if (empty($exchangeRate['short'])) {
                continue;
            }

            $currencies[] = new CurrencyRate($exchangeRate['short'], $exchangeRate['rate']);
        }

        $this->cache = $currencies;

        return $currencies;
    }
}
