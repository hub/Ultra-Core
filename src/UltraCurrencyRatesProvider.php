<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\CurrencyRate;

/**
 * This provided the rate of an Ultra asset per one 1 Ven.
 *
 * @package Hub\UltraCore
 */
class UltraCurrencyRatesProvider implements CurrencyRatesProviderInterface
{
    /**
     * @var UltraAssetsRepository
     */
    private $ultraAssetsRepository;

    /**
     * UltraCurrencyRatesProviderFactory constructor.
     *
     * @param UltraAssetsRepository $ultraAssetsRepository
     */
    public function __construct(UltraAssetsRepository $ultraAssetsRepository)
    {
        $this->ultraAssetsRepository = $ultraAssetsRepository;
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
        $ultraAssets = $this->ultraAssetsRepository->getAllActiveAssets();
        if (empty($ultraAssets)) {
            return [];
        }

        $rates = [];
        foreach ($ultraAssets as $ultraAsset) {
            $rates[] = new CurrencyRate(
                $ultraAsset->tickerSymbol(),
                $this->ultraAssetsRepository->getAssetAmountForOneVen($ultraAsset)->getAmount()
            );
        }

        return $rates;
    }
}
