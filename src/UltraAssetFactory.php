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

        return new UltraAsset(
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
    }

    /**
     * @param string $weightingsString
     * @param string $weightingType
     * @param float  $explicitVenAmount
     *
     * @return UltraAssetWeighting[]
     */
    public static function extractAssetWeightings($weightingsString, $weightingType, $explicitVenAmount = 0.0)
    {
        if ($weightingType !== UltraAssetsRepository::TYPE_CURRENCY_COMBO) {
            return array(
                new UltraAssetWeighting(
                    UltraAssetsRepository::CURRENCY_CODE_VEN_LABEL,
                    $explicitVenAmount,
                    100
                ),
            );
        }

        $rawWeightings = @json_decode($weightingsString, true);
        if (json_last_error() != JSON_ERROR_NONE) {
            return array();
        }

        if (!is_array($rawWeightings)) {
            return array();
        }

        $weightings = array();
        foreach ($rawWeightings as $rawWeighting) {
            // default currency amount zero(0) as it will be calculated later in the pipeline.
            $currencyAmount = 0;

            // however if the combined currency is 'Ven' itself, it is always 1. Coz simply: 1 Ven = 1 Ven
            if (strtolower($rawWeighting['type']) === 'ven') {
                $rawWeighting['type'] = UltraAssetsRepository::CURRENCY_CODE_VEN_LABEL;
                $currencyAmount = 1;
            }

            $weightings[] = new UltraAssetWeighting(
                $rawWeighting['type'],
                $currencyAmount,
                $rawWeighting['amount']
            );
        }

        return $weightings;
    }
}
