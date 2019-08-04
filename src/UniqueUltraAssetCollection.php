<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 24-06-2018
 */

namespace Hub\UltraCore;

class UniqueUltraAssetCollection implements UltraAssetCollection
{
    /**
     * @var UltraAsset[]
     */
    private $assets;

    /**
     * @param UltraAsset $asset
     */
    public function addAsset(UltraAsset $asset)
    {
        if (!isset($this->assets[$asset->weightingHash()])) {
            $this->assets[$asset->weightingHash()] = $asset;
            return;
        }

        // this means that this asset's weighting config is a duplicate of an existing asset

        /** @var UltraAsset $existingAsset */
        $existingAsset = $this->assets[$asset->weightingHash()];

        // let's add the duplicated assets quantity into the existing assets quantity
        $existingAsset->incrementAssetsQuantity($asset->numAssets());
        // let's override the very first found assets title, ticker with the base currency details
        $mainWeighting = $existingAsset->getAssetWeightingByPercentage();
        $existingAsset->setTitle($mainWeighting->currencyName());
        $existingAsset->setTickerSymbol(sprintf('u%s', strtoupper(strtoupper($mainWeighting->currencyName()))));

        // reset the updated asset
        $this->assets[$asset->weightingHash()] = $existingAsset;
    }

    /**
     * @return UltraAsset[]
     */
    public function getAssets()
    {
        return $this->assets;
    }
}
