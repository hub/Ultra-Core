<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Issuance;

use Hub\UltraCore\UltraAsset;
use mysqli;

/**
 * This randomly selects a qualifying ultra issuer to use their assets to sell. If none can qualify for the requested
 * amount, it then falls back to the FirstIssuerFirstServedIssuerSelectionStrategy.
 *
 * Class RandomIssuerSelectionStrategy
 * @package Hub\UltraCore\Issuance
 */
class RandomIssuerSelectionStrategy implements IssuerSelectionStrategy
{
    /**
     * @var mysqli
     */
    private $dbConnection;

    /**
     * @var IssuerSelectionStrategy
     */
    private $issuerSelectionStrategy;

    /**
     * RandomIssuerSelectionStrategy constructor.
     *
     * @param mysqli                                        $dbConnection
     * @param FirstIssuerFirstServedIssuerSelectionStrategy $issuerSelectionStrategy
     */
    public function __construct(
        mysqli $dbConnection,
        FirstIssuerFirstServedIssuerSelectionStrategy $issuerSelectionStrategy
    ) {
        $this->dbConnection = $dbConnection;
        $this->issuerSelectionStrategy = $issuerSelectionStrategy;
    }

    /**
     * This decides whose allotment to be used to process a given required asset quantity.
     * This will randomly selects one authority issuer who has got a remaining quantity greater than the requested
     * quantity.
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
    AND `remaining_asset_quantity` > {$requiredQuantity}
ORDER BY RAND()
LIMIT 1
SQL
        );

        $issuers = array();
        while ($issuance = $stmt->fetch_assoc()) {
            $issuers[] = new AssetIssuerAuthority(
                $issuance['user_id'],
                floatval($issuance['original_quantity_issued']),
                floatval($issuance['remaining_asset_quantity']),
                $requiredQuantity
            );
        }

        // if none has a remaining quantity of the required amount, we need to get it from another strategy
        if (empty($issuers)) {
            $issuers = $this->issuerSelectionStrategy->select($originalAssetId, $requiredQuantity);
        }

        return $issuers;
    }
}
