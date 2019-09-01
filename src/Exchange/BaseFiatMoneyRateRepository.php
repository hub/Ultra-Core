<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Exchange;

interface BaseFiatMoneyRateRepository
{
    /**
     * This returns the very base currencies with the exchange rate against 1 Ven.
     *
     * @return BaseFiatMoney[]
     */
    public function getBaseFiatMoney();
}
