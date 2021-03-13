<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\CurrencyRate;

/**
 * Implement this to provide various currency rates with respect to the primary currency given.
 *
 * @package Hub\UltraCore
 */
interface CurrencyRatesProviderInterface
{
    /**
     * Use this to retrieve currency exchange rates for a given primary currency.
     * ex: if you want to find out how many US Dollars needed for 1 Ven, then you need to pass here 'VEN'
     *
     * @param Currency $primaryCurrency Primary currency.
     *
     * @return CurrencyRate[]
     */
    public function getByPrimaryCurrencySymbol(Currency $primaryCurrency);
}
