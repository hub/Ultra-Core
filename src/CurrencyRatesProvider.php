<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @date   : 30-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\CurrencyRate;
use mysqli;

class CurrencyRatesProvider implements CurrencyRatesProviderInterface
{
    /**
     * @var mysqli
     */
    private $dbConnection;

    /**
     * @var array
     */
    private $cache;

    /**
     * @param mysqli $dbConnection
     */
    public function __construct(mysqli $dbConnection)
    {
        $this->dbConnection = $dbConnection;
    }

    /**
     * Use this to retrieve currency exchange rates for a given primary currency.
     * ex: if you want to find out how many US Dollars needed for 1 Ven, then you need to pass here 'VEN'
     *
     * @param Currency $primaryCurrency Primary currency.
     *
     * @return CurrencyRate[]
     */
    public function getByPrimaryCurrencySymbol(Currency $primaryCurrency)
    {
        $currencySymbol = $primaryCurrency->getStringRepresentation();
        if (!empty($this->cache[$currencySymbol])) {
            return $this->cache[$currencySymbol];
        }

        $sql = "SELECT * FROM `currency_chart` WHERE `primary_currency` = '{$currencySymbol}'";

        $resultSet = $this->dbConnection->query($sql);
        if (empty($resultSet)) {
            return array();
        }

        // traverse through the results to create an array of data rows
        $rows = array();
        while ($row = $resultSet->fetch_assoc()) {
            $rows[] = new CurrencyRate($row['secondary_currency'], $row['current_amount']);
        }

        $this->cache[$currencySymbol] = $rows;

        return $rows;
    }
}
