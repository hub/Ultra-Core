<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 27/06/2018
 */

namespace Hub\UltraCore;

interface UltraAssetCollection
{
    /**
     * @param UltraAsset $asset
     */
    public function addAsset(UltraAsset $asset);

    /**
     * @return UltraAsset[]
     */
    public function getAssets();
}
