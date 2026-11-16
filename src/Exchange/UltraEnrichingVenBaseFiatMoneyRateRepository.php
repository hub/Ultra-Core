<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Exchange;

use Hub\UltraCore\UltraAssetsRepository;

/**
 * This is the front facing component where we put together all the fiat currencies as far as the Hub Culture exchange
 * is concerned.
 *
 * This enriches the very base fiat currencies such as USD, GBP, AUD with any associated ultra asset.
 * We do that by looking for an ultra asset with 100% composition of a base fiat currency. If we do, then we associate
 * it to the base fiat currency and is trade-able within the Ultra Exchange.
 *
 * In case we don't have such ultra assets, we make those ultra assets a fiat currency within our Ultra exchange. These
 * ultra fiats currencies always have more than one(1) base fiat currency within their composition.
 *
 * @package Hub\UltraCore\Exchange
 */
class UltraEnrichingVenBaseFiatMoneyRateRepository implements BaseFiatMoneyRateRepository
{
    /**
     * Currencies whose own Ultra asset isn't named "u" + the currency code.
     * CARBON (ICE EUA) trades as uCRB, Ven Carbon (asset 19), which is 100% CARBON.
     * Keep this list short: every other currency needs an exact "u" + code ticker,
     * so a third-party asset weighted 100% to a currency (Dropp, #141) can't take it over.
     */
    const OWN_ASSET_EXCEPTIONS = [
        'CARBON' => 'uCRB',
    ];

    /**
     * @var BaseFiatMoneyRateRepository
     */
    private $innerExchangeRateRepository;

    /**
     * @var UltraAssetsRepository
     */
    private $ultraAssetsRepository;

    /**
     * UltraEnrichingVenExchangeRateRepository constructor.
     *
     * @param VenBaseFiatMoneyRateRepository $innerExchangeRateRepository
     * @param UltraAssetsRepository          $ultraAssetsRepository
     */
    public function __construct(
        VenBaseFiatMoneyRateRepository $innerExchangeRateRepository,
        UltraAssetsRepository $ultraAssetsRepository
    ) {
        $this->innerExchangeRateRepository = $innerExchangeRateRepository;
        $this->ultraAssetsRepository = $ultraAssetsRepository;
    }

    /**
     * This returns the very base currencies with the exchange rate against 1 Ven.
     *
     * @return BaseFiatMoney[]
     */
    public function getBaseFiatMoney()
    {
        $baseExchangeRates = $this->innerExchangeRateRepository->getBaseFiatMoney();

        $assets = $this->ultraAssetsRepository->getAllActiveAssets();
        foreach ($assets as $asset) {
            // check to see if we have 100% weighting of a base fiat currency on the current Ultra asset.
            // if so, relate to the corresponding fiat currency itself and continue to the next ultra asset.
            // Only the u-asset of the currency itself (uUSD for USD) stands for it. Any other asset that happens to be
            // 100% USD (Dropp, #141, basket "USD 100") is listed under its own ticker below, never as USD.
            $fullBaseWeighting = $asset->getAssetWeightingByPercentage(100);
            if ($asset->isWithOneWeighting()
                && !is_null($fullBaseWeighting)
                && !empty($baseExchangeRates[$fullBaseWeighting->currencyName()])
                && self::isOwnAsset($fullBaseWeighting->currencyName(), $asset->tickerSymbol())
            ) {
                $baseExchangeRates[$fullBaseWeighting->currencyName()]->setUltraAssetComposedCurrencyCount(1);
                $baseExchangeRates[$fullBaseWeighting->currencyName()]->setUltraAssetId($asset->id());
                continue;
            }

            // let's continue to the next if set already to avoid overwriting
            if (isset($baseExchangeRates[$asset->tickerSymbol()])) {
                continue;
            }

            $baseExchangeRates[$asset->tickerSymbol()] = new BaseFiatMoney(
                $this->ultraAssetsRepository->getAssetAmountForOneVen($asset)->getAmount(),
                $asset->getCurrency(),
                $asset->title(),
                time(),
                $asset->id(),
                count($asset->weightings())
            );
        }

        return $baseExchangeRates;
    }

    /**
     * Whether $ticker is the currency's own Ultra asset: "u" + the code (USD -> uUSD), or
     * the asset in OWN_ASSET_EXCEPTIONS (CARBON -> uCRB).
     *
     * @param string $currency "USD" or "CARBON"
     * @param string $ticker   "uUSD" or "uCRB"
     *
     * @return bool
     */
    public static function isOwnAsset($currency, $ticker)
    {
        $currency = strtoupper((string)$currency);
        if (isset(self::OWN_ASSET_EXCEPTIONS[$currency])) {
            return strcasecmp((string)$ticker, self::OWN_ASSET_EXCEPTIONS[$currency]) === 0;
        }

        return strcasecmp((string)$ticker, 'u' . $currency) === 0;
    }
}
