<?php

namespace Hub\UltraCore\Support;

use Hub\UltraCore\UltraAsset;
use Hub\UltraCore\UltraAssetsRepository;

/**
 * Ultra assets in memory, with the issuance supply the match engine reduces.
 */
class FakeAssetsRepository extends UltraAssetsRepository
{
    /** @var UltraAsset[] */
    public $assets = array();

    /** @var array asset id => bool|null, what hasBondTerms() answers */
    public $bondTerms = array();

    /** @var array asset id => float */
    public $totalDeducted = array();

    public function __construct()
    {
        // no database
    }

    /**
     * A fixed-price (custom Ven amount) asset.
     */
    public function add($id, $venPrice, $category = 'object', $numAssets = 1000)
    {
        $this->assets[$id] = new UltraAsset(
            $id, 'hash' . $id, 'Asset ' . $id, '', $category, 'uA' . $id, $numAssets, '', '', true, false, 500,
            UltraAssetsRepository::TYPE_VEN_AMOUNT, array(), (string)$venPrice, '', date('Y-m-d H:i:s')
        );
    }

    public function getAssetById($assetId)
    {
        return isset($this->assets[$assetId]) ? $this->assets[$assetId] : null;
    }

    public function hasBondTerms($assetId)
    {
        return array_key_exists($assetId, $this->bondTerms) ? $this->bondTerms[$assetId] : false;
    }

    public function deductTotalAssetQuantityBy($assetId, $quantity)
    {
        $this->totalDeducted[$assetId] = (isset($this->totalDeducted[$assetId]) ? $this->totalDeducted[$assetId] : 0) + $quantity;

        return true;
    }

    public function deductAssetQuantityBy($authorityIssuerId, $assetId, $quantity)
    {
        return true;
    }
}
