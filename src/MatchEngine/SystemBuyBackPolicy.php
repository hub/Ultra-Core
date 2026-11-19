<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\MatchEngine;

use Hub\UltraCore\UltraAsset;
use Hub\UltraCore\UltraAssetsRepository;

/**
 * Which unmatched sell orders the system account (user 1) may buy back at the asset price (BuyerIssuerMatcher).
 *
 * Bonds are never bought back by default: at face value the system account would accumulate Emerald City bonds
 * and then collect their coupons and principal from the bonds account (hub/HubID#329). A bond is an asset whose
 * category is `bond`, or one with a row in `ultra_bond_terms`. When that can't be read, the asset is treated as
 * a bond (default to safety: the sell order just stays on the order book).
 *
 * To allow it (Stan to confirm), construct with allowBonds = true; other categories can be excluded the same way.
 */
class SystemBuyBackPolicy
{
    const BOND_CATEGORY = 'bond';

    /** @var string[] lower-case categories the system account never buys */
    private $excludedCategories;

    /** @var bool */
    private $allowBonds;

    /** @var UltraAssetsRepository|null */
    private $assetsRepository;

    /**
     * @param UltraAssetsRepository|null $assetsRepository   to look up `ultra_bond_terms`; null checks the category only
     * @param bool                       $allowBonds         false (default): never buy back bonds
     * @param string[]                   $excludedCategories other categories never bought back
     */
    public function __construct(?UltraAssetsRepository $assetsRepository = null, $allowBonds = false, array $excludedCategories = array())
    {
        $this->assetsRepository = $assetsRepository;
        $this->allowBonds = (bool)$allowBonds;
        $this->excludedCategories = array_values(array_filter(array_map(function ($category) {
            return strtolower(trim((string)$category));
        }, $excludedCategories)));
    }

    /**
     * @param UltraAsset $asset
     *
     * @return string|null why the system account must not buy this asset back, or null when it may
     */
    public function refusalReason(UltraAsset $asset)
    {
        $category = strtolower(trim((string)$asset->category()));
        if (in_array($category, $this->excludedCategories, true)) {
            return "assets in the '{$category}' category are not bought back by the system account";
        }
        if ($this->allowBonds) {
            return null;
        }
        if ($category === self::BOND_CATEGORY) {
            return 'bonds are not bought back by the system account';
        }
        if ($this->assetsRepository !== null) {
            $hasTerms = $this->assetsRepository->hasBondTerms($asset->id());
            if ($hasTerms === null) {
                return 'it cannot be told whether the asset is a bond, so it is not bought back';
            }
            if ($hasTerms) {
                return 'bonds are not bought back by the system account';
            }
        }

        return null;
    }
}
