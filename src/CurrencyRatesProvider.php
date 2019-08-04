<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 30-06-2018
 */

namespace Hub\UltraCore;

use mysqli;

class CurrencyRatesProvider
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
     * @param string $primaryCurrency
     * @return array
     */
    public function getByPrimaryCurrencySymbol($primaryCurrency)
    {
        if (!empty($this->cache[$primaryCurrency])) {
            return $this->cache[$primaryCurrency];
        }

        $sql = "SELECT * FROM `currency_chart` WHERE `primary_currency` = '{$primaryCurrency}'";

        $resultSet = $this->dbConnection->query($sql);
        if (empty($resultSet)) {
            return array();
        }

        // traverse through the results to create an array of data rows
        $rows = array();
        while($row = $resultSet->fetch_assoc()) {
            $rows[] = $row;
        }

        $this->cache[$primaryCurrency] = $rows;

        return $rows;
    }
}
