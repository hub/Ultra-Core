<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 01-07-2018
 */

namespace Hub\UltraCore;

class UltraAssetFactory
{
    /**
     * @param array $asset
     *
     * @return UltraAsset
     */
    public static function fromArray(array $asset)
    {
        $explicitVenAmount = 0.0;
        if (!empty($asset['explicit_ven_amount'])) {
            $explicitVenAmount = floatval($asset['explicit_ven_amount']);
        }

        $assetObj = new UltraAsset(
            $asset['id'],
            $asset['hash'],
            $asset['title'],
            $asset['category'],
            $asset['ticker_symbol'],
            $asset['num_assets'],
            $asset['background_image'],
            $asset['icon_image'],
            $asset['is_approved'],
            $asset['is_featured'],
            $asset['user_id'],
            $asset['weighting_type'],
            self::extractAssetWeightings($asset['weightings'], $asset['weighting_type'], $explicitVenAmount),
            $asset['created_at']
        );

        if (isset($asset['isMergedAsset']) && intval($asset['isMergedAsset']) === 1) {
            $assetObj->markAsMergedAsset();
        }

        return $assetObj;
    }

    /**
     * @param string $weightingsString
     * @param string $weightingType
     * @param float $explicitVenAmount
     *
     * @return UltraAssetWeighting[]
     */
    public static function extractAssetWeightings($weightingsString, $weightingType, $explicitVenAmount = 0.0)
    {
        if ($weightingType !== UltraAssetsRepository::TYPE_CURRENCY_COMBO) {
            return array(new UltraAssetWeighting('Ven', $explicitVenAmount, 100));
        }

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
