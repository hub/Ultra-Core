<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Issuance;

use Hub\UltraCore\UltraAsset;

/**
 * Implement this to select similar ultra asset issuers for a given quantity from a main ultra asset issuance.
 *
 * Multiple authorities can mint / launch new ultra assets having a similar weighting configurations to existing ones.
 * Therefore during a asset purchase order, we need to be able to select the assets from multiple asset issuers, to be
 * able to credit them depending on the amount being purchased. The original issuer of the main asset may have NOT
 * issued sufficient amount of assets for a the required quantity being purchased.
 *
 * Therefore an ultra asset issuer selection strategy needed to be used to select issuers together with the quantity.
 *
 * Interface IssuerSelectionStrategy
 * @package Hub\UltraCore\Issuance
 */
interface IssuerSelectionStrategy
{
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
     * @param UltraAsset $originalAsset    Main ultra asset to be considered when selecting.
     * @param float      $requiredQuantity Required asset quantity.
     *
     * @return AssetIssuerAuthority[] returns an array of authority issuers.
     */
    public function select(UltraAsset $originalAsset, $requiredQuantity);
}
