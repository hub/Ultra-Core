<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Issuance;

use Hub\UltraCore\UltraAsset;
use mysqli;

/**
 * This go through all the issuers to find the sell-able quantities of assets.
 * This goes through the issuer authority users as they have issued assets. The very first users who issued the assets
 * will be processed first.
 *
 * Class FirstIssuerFirstServedIssuerSelectionStrategy
 * @package Hub\UltraCore\Issuance
 */
class FirstIssuerFirstServedIssuerSelectionStrategy implements IssuerSelectionStrategy
{
    /**
     * @var mysqli
     */
    private $dbConnection;

    /**
     * FirstIssuerFirstServedIssuerSelectionStrategy constructor.
     *
     * @param mysqli $dbConnection
     */
    public function __construct(mysqli $dbConnection)
    {
        $this->dbConnection = $dbConnection;
    }

    /**
     * This decides whose allotment to be used to process a given required asset quantity. Use this to determine how
     * much assets can be bought from which authority.
     *
     * Example:
     * Given 'Authority A' has issued: 50 uUSD assets
     * And 'Authority B' has issued: 20 uUSD assets
     * And 'Authority C' has issued: 30 uUSD assets
     * When a request comes in to require 90 uUSD assets.
     * This function will select 50, 20 & 20 from Authorities A, B & C respectively leaving 10 uUSD from 'Authority C'.
     *
     * @param int   $originalAssetId  Main ultra asset to be considered when selecting.
     * @param float $requiredQuantity Required asset quantity.
     *
     * @return AssetIssuerAuthority[] returns an array of authority issuers.
     */
    public function select($originalAssetId, $requiredQuantity)
    {
        $requiredQuantity = floatval($requiredQuantity);
        $stmt = $this->dbConnection->query(<<<SQL
SELECT
    `user_id`,
    `original_quantity_issued`,
    `remaining_asset_quantity`
FROM `ultra_asset_issuance_history`
WHERE
    `asset_id` = {$originalAssetId}
ORDER BY `id` ASC
SQL
        );

        $issuers = array();
        while ($issuance = $stmt->fetch_assoc()) {
            if ($requiredQuantity <= 0) {
                break;
            }

            $assetIssuerAuthority = new AssetIssuerAuthority(
                $issuance['user_id'],
                floatval($issuance['original_quantity_issued']),
                floatval($issuance['remaining_asset_quantity'])
            );
            if (floatval($issuance['remaining_asset_quantity']) > $requiredQuantity) {
                $assetIssuerAuthority->setSaleableAssetQuantity($requiredQuantity);
                $requiredQuantity = 0;
            } else {
                $assetIssuerAuthority->setSaleableAssetQuantity(floatval($issuance['remaining_asset_quantity']));
                $requiredQuantity = $requiredQuantity - $issuance['remaining_asset_quantity'];
            }

            $issuers[] = $assetIssuerAuthority;
        }

        return $issuers;
    }
}
