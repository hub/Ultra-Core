<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
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
