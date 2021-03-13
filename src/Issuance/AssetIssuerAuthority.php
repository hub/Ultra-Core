<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Issuance;

/**
 * Class AssetIssuerAuthority
 * @package Hub\UltraCore\Issuance
 */
class AssetIssuerAuthority
{
    /**
     * @var int
     */
    private $authorityUserId;

    /**
     * @var float
     */
    private $originalQuantityIssued;

    /**
     * @var float
     */
    private $remainingAssetQuantity;

    /**
     * @var float
     */
    private $saleableAssetQuantity;

    /**
     * AssetIssuerAuthority constructor.
     *
     * @param int   $authorityUserId        Hub authority user unique identifier.
     * @param float $originalQuantityIssued Issued Number of asset issued at the time of issuance.
     * @param float $remainingAssetQuantity The remaining number of assets.
     * @param float $saleableAssetQuantity  [optional] The amount which is sell-able off of the remaining asset
     *                                      quantity. ex: During a purchase order from a buyer.
     */
    public function __construct(
        $authorityUserId,
        $originalQuantityIssued,
        $remainingAssetQuantity,
        $saleableAssetQuantity = 0.0
    ) {
        $this->authorityUserId = $authorityUserId;
        $this->originalQuantityIssued = $originalQuantityIssued;
        $this->remainingAssetQuantity = $remainingAssetQuantity;
        $this->saleableAssetQuantity = $saleableAssetQuantity;
    }

    /**
     * @return int
     */
    public function getAuthorityUserId()
    {
        return $this->authorityUserId;
    }

    /**
     * @return float
     */
    public function getOriginalQuantityIssued()
    {
        return $this->originalQuantityIssued;
    }

    /**
     * @return float
     */
    public function getRemainingAssetQuantity()
    {
        return $this->remainingAssetQuantity;
    }

    /**
     * Returns the amount which is sell-able off of the remaining asset quantity.
     *
     * @return float
     */
    public function getSaleableAssetQuantity()
    {
        return $this->saleableAssetQuantity;
    }

    /**
     * @param float $saleableAssetQuantity The amount which is sell-able off of the remaining asset quantity.
     *                                     ex: During a purchase order from a buyer.
     */
    public function setSaleableAssetQuantity($saleableAssetQuantity)
    {
        $this->saleableAssetQuantity = $saleableAssetQuantity;
    }
}
