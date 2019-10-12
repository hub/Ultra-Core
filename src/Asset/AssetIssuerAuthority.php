<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Asset;

/**
 * Class AssetIssuerAuthority
 * @package Hub\UltraCore\Asset
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
    private $usableAssetQuantity;

    /**
     * AssetIssuerAuthority constructor.
     *
     * @param int   $authorityUserId        Hub authority user unique identifier.
     * @param float $originalQuantityIssued Issued Number of asset issued at the time of issuance.
     * @param float $remainingAssetQuantity The remaining number of assets.
     * @param float $usableAssetQuantity    [optional] Amount which is usable for any action like a buy order from this
     *                                      authority.
     */
    public function __construct(
        $authorityUserId,
        $originalQuantityIssued,
        $remainingAssetQuantity,
        $usableAssetQuantity = 0.0
    ) {
        $this->authorityUserId = $authorityUserId;
        $this->originalQuantityIssued = $originalQuantityIssued;
        $this->remainingAssetQuantity = $remainingAssetQuantity;
        $this->usableAssetQuantity = $usableAssetQuantity;
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
     * This is the amount which can be used off of this authority users issued asset pool.
     *
     * @return float
     */
    public function getUsableAssetQuantity()
    {
        return $this->usableAssetQuantity;
    }

    /**
     * @param float $usableAssetQuantity    Amount which is usable for any action like a 'buy order' from this
     *                                      authority.
     */
    public function setUsableAssetQuantity($usableAssetQuantity)
    {
        $this->usableAssetQuantity = $usableAssetQuantity;
    }
}
