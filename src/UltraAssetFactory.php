<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 01-07-2018
 */

namespace Hub\UltraCore;

class UltraAssetFactory
{
    /**
     * @return UltraAsset
     */
    public static function fromArray(array $asset)
    {
        $assetObj = new UltraAsset(
            $asset['id'],
            $asset['hash'],
            $asset['title'],
            $asset['ticker_symbol'],
            $asset['num_assets'],
            $asset['background_image'],
            $asset['is_approved'],
            $asset['is_featured'],
            $asset['user_id'],
            self::extractAssetWeightings($asset['weightings'])
        );

        if (isset($asset['isMergedAsset']) && intval($asset['isMergedAsset']) === 1) {
            $assetObj->markAsMergedAsset();
        }

        return $assetObj;
    }

    /**
     * @return UltraAssetWeighting[]
     */
    public static function extractAssetWeightings($weightingsString)
    {
        $rawWeightings = json_decode($weightingsString, true);
        $weightings = array();
        if (is_array($rawWeightings)) {
            foreach ($rawWeightings as $rawWeighting) {
                $weightings[] = new UltraAssetWeighting($rawWeighting['type'], 0, $rawWeighting['amount']);
            }
        }

        return $weightings;
    }
}
