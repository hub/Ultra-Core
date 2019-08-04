<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 03-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Exception\InsufficientAssetAvailabilityException;
use TSK\ResultSetPaginator\PaginationFactory;
use mysqli;

class UltraAssetsRepository
{
    const CURRENCY_CODE_VEN = 'VEN';
    const DECIMAL_SEPERATOR = '.';

    /**
     * @var mysqli
     */
    private $dbConnection;

    /**
     * @var CurrencyRatesProvider
     */
    private $currencyRatesProvider;

    /**
     * @param mysqli $dbConnection
     * @param CurrencyRatesProvider $currencyRatesProvider
     */
    public function __construct(
        mysqli $dbConnection, 
        CurrencyRatesProvider $currencyRatesProvider
    ) {
        $this->dbConnection = $dbConnection;
        $this->currencyRatesProvider = $currencyRatesProvider;
    }

    /**
     * @return UltraAssetCollection
     */
    public function getAllActiveAssets()
    {
        /** @var \mysqli_result $stmt */
        $stmt = $this->dbConnection->query($this->getUltraAssetRetrievalQuery());

        $assetCollection = new UniqueUltraAssetCollection();
        if ($stmt->num_rows === 0) {
            $assetCollection;
        }

        while ($asset = $stmt->fetch_assoc()) {
            $assetCollection->addAsset($this->getWeightingEnrichedUltraAsset($asset));
        }

        return $assetCollection;
    }

    /**
     * @return array [AbstractResultSetPaginator, UltraAssetCollection]
     */
    public function getAllActiveAssetsWithPagination($page = 1, $limit = 10)
    {
        $paginationFactory = new PaginationFactory($this->dbConnection, $page, $limit);
        $paginator = $paginationFactory->getPaginator();

        /** @var \mysqli_result $stmt */
        $stmt = $paginator->query($this->getUltraAssetRetrievalQuery());

        $assetCollection = new UniqueUltraAssetCollection();
        if ($stmt->num_rows === 0) {
            return array($paginator, $assetCollection);
        }

        while ($asset = $stmt->fetch_assoc()) {
            $assetCollection->addAsset($this->getWeightingEnrichedUltraAsset($asset));
        }

        return array($paginator, $assetCollection);
    }

    /**
     * @param int $assetId
     * @return UltraAsset|null
     */
    public function getAssetById($assetId)
    {
        /** @var \mysqli_result $stmt */
        $stmt = $this->dbConnection->query("SELECT * FROM ultra_assets WHERE id = {$assetId}");
        if ($stmt->num_rows === 0) {
            return null;
        }

        return $this->getWeightingEnrichedUltraAsset($stmt->fetch_assoc());
    }

    /**
     * @param int $assetId
     * @param float $quantity
     */
    public function deductAssetQuantityBy($assetId, $quantity)
    {
        $this->dbConnection->query("UPDATE ultra_assets SET num_assets = num_assets - {$quantity} WHERE id = {$assetId}");
    }

    /**
     * @param UltraAsset $asset
     * @return float
     */
    public function getVenAmountForOneAsset(UltraAsset $asset)
    {
        $equivalentAssetAmountForOneVen = $this->getAssetValue($asset);

        $oneAssetPriceInVen = 1 / $equivalentAssetAmountForOneVen;
        $amountParts = explode(self::DECIMAL_SEPERATOR, $oneAssetPriceInVen);

        // absolute precision value WITHOUT doing any round/ceil/floor
        return floatval(
            $amountParts[0] . self::DECIMAL_SEPERATOR . substr($amountParts[1], 0, 4)
        );
    }

    /**
     * @param UltraAsset $asset
     * @return float
     */
    public function getAssetAmountForOneVen(UltraAsset $asset)
    {
        $equivalentAssetAmountForOneVen = $this->getAssetValue($asset);

        $assetValueForNVen = 1 * $equivalentAssetAmountForOneVen;
        $amountParts = explode(self::DECIMAL_SEPERATOR, $assetValueForNVen);

        // absolute precision value WITHOUT doing any round/ceil/floor
        return floatval(
            $amountParts[0] . self::DECIMAL_SEPERATOR . substr($amountParts[1], 0, 4)
        );
    }

    /**
     * @param UltraAsset $asset
     * @param bool $isFormatted
     * @return float
     */
    public function getAssetValue(UltraAsset $asset, $isFormatted = false)
    {
        $equivalentAssetAmountForOneVen = 0;
        foreach ($asset->weightings() as $weighting) {
            $weightingData = $weighting->toArray();

            $equivalentAssetAmountForOneVen += $weightingData['percentage_amount'];
        }

        if (!$isFormatted) {
            return $equivalentAssetAmountForOneVen;
        }

        $amountParts = explode(self::DECIMAL_SEPERATOR, $equivalentAssetAmountForOneVen);

        // absolute precision value WITHOUT doing any round/ceil/floor
        return floatval(
            $amountParts[0] . self::DECIMAL_SEPERATOR . substr($amountParts[1], 0, 4)
        );
    }

    /**
     * @param UltraAsset $asset
     */
    public function enrichAssetWeightingAmounts(UltraAsset &$asset)
    {
        $currencies = $this->currencyRatesProvider->getByPrimaryCurrencySymbol(self::CURRENCY_CODE_VEN);

        $assetWeightings = array();
        foreach ($asset->weightings() as $weighting) {
            foreach ($currencies as $currency) {
                if ($currency['secondary_currency'] !== $weighting->currencyName()) {
                    continue;
                }

                $assetWeightings[] = new UltraAssetWeighting(
                    $currency['secondary_currency'],
                    $currency['current_amount'],
                    $weighting->percentage()
                );
            }
        }

        $asset->setWeightings($assetWeightings);
    }

    /**
     * @param UltraAsset $asset
     * @return UltraAsset[]
     */
    public function getSimilarAssetsForAsset(UltraAsset $asset)
    {
        /** @var \mysqli_result $stmt */
        $stmt = $this->dbConnection->query(<<<SQL
SELECT
    *
FROM ultra_assets
WHERE
    `hash` = '{$asset->weightingHash()}'
    AND is_approved = 1
ORDER BY RAND()
SQL
        );

        $assets = [];
        while ($row = $stmt->fetch_assoc()) {
            $assetObj = UltraAssetFactory::fromArray($row);
            // we can set the same weightings as they are similar in weightings
            $assetObj->setWeightings($asset->weightings());

            $assets[] = $assetObj;
        }

        return $assets;
    }

    /**
     * returns the quantity to be deducted from assets as per required quantity
     *
     * @param UltraAsset $asset
     * @param $requiredQuantity
     * @return array [UltraAsset, float][]
     * @throws InsufficientAssetAvailabilityException
     */
    public function getQuantitiesPerSimilarAsset(UltraAsset $asset, $requiredQuantity)
    {
        $quantitiesPerAssets = [];
        $availableAssetAmount = 0;
        $originalRequiredQuantity = $requiredQuantity;

        foreach ($this->getSimilarAssetsForAsset($asset) as $similarAsset) {
            $availableAssetAmount += $similarAsset->numAssets();
            if (!($requiredQuantity > 0)) {
                continue;
            }

            $eachAssetQuantity = $similarAsset->numAssets();

            if ($requiredQuantity < $eachAssetQuantity) {
                $quantity = $requiredQuantity;
            } else {
                $quantity = $eachAssetQuantity;
            }

            $requiredQuantity -= $quantity;
            $quantitiesPerAssets[] = ['asset' => $similarAsset, 'quantity' => $quantity];
        }

        if ($requiredQuantity > 0) {
            throw new InsufficientAssetAvailabilityException(sprintf(
                'There are no such amount of assets available for your requested amount of %s. Only %s available.',
                $originalRequiredQuantity,
                $availableAssetAmount
            ));
        }

        return $quantitiesPerAssets;
    }

    private function getWeightingEnrichedUltraAsset(array $asset)
    {
        $assetObj = UltraAssetFactory::fromArray($asset);

        $this->enrichAssetWeightingAmounts($assetObj);

        return $assetObj;
    }

    private function getUltraAssetRetrievalQuery()
    {
        return <<<SQL
SELECT
    *,
    IF (COUNT(`hash`) > 1, 1, 0) AS `isMergedAsset`,
    SUM(`num_assets`) AS `numAssets`
FROM `ultra_assets`
WHERE
    `is_approved` = 1
    AND `num_assets` > 0
GROUP BY `hash`
SQL;
    }
}
