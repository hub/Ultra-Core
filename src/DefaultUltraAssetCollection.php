<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 24-06-2018
 */

namespace Hub\UltraCore;

class DefaultUltraAssetCollection implements UltraAssetCollection
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
        $this->assets[$asset->weightingHash()] = $asset;
    }

    /**
     * @return UltraAsset[]
     */
    public function getAssets()
    {
        return $this->assets;
    }
}
