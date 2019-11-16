<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 03-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Money;
use Hub\UltraCore\Exception\InsufficientAssetAvailabilityException;
use Exception;
use mysqli;

class UltraAssetsRepository
{
    const CURRENCY_CODE_VEN = 'VEN';
    const DECIMAL_SEPARATOR = '.';
    const TYPE_CURRENCY_COMBO = 'currency_combination';
    const TYPE_VEN_AMOUNT = 'custom_ven_amount';
    const TYPE_EXTERNAL_ENTITY = 'external_entity_with_description';

    public static $availableWeightingTypes = array(
        self::TYPE_CURRENCY_COMBO => 'Combining Other Currencies',
        self::TYPE_VEN_AMOUNT => 'Custom VEN amount per asset',
        self::TYPE_EXTERNAL_ENTITY => 'Relate to external entity with proof attached/described',
    );

    public static $availableAssetCategories = array(
        'object' => 'An Object',
        'art' => 'Art',
        'fiat' => 'Fiat',
        'land' => 'Land',
        'real_estate' => 'Real Estate',
        'reward' => 'Reward',
        'car' => 'Vehicle',
    );

    /**
     * @var mysqli
     */
    private $dbConnection;

    /**
     * @var CurrencyRatesProviderInterface
     */
    private $currencyRatesProvider;

    /**
     * @param mysqli $dbConnection
     * @param CurrencyRatesProviderInterface $currencyRatesProvider
     */
    public function __construct(
        mysqli $dbConnection,
        CurrencyRatesProviderInterface $currencyRatesProvider
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
            return $assetCollection;
        }

        while ($asset = $stmt->fetch_assoc()) {
            $asset['num_assets'] = $asset['numAssets'];
            $assetCollection->addAsset($this->getWeightingEnrichedUltraAsset($asset));
        }

        return $assetCollection;
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
     * @param string $ticker unique ticker symbol of a ultra asset. ex: uBMD
     * @return UltraAsset|null
     */
    public function getAssetByTicker($ticker)
    {
        /** @var \mysqli_result $stmt */
        $stmt = $this->dbConnection->query("SELECT * FROM ultra_assets WHERE ticker_symbol = '{$ticker}'");
        if ($stmt->num_rows === 0) {
            return null;
        }

        return $this->getWeightingEnrichedUltraAsset($stmt->fetch_assoc());
    }

    /**
     * @param int $assetId
     * @param float $quantity
     */
    public function deductTotalAssetQuantityBy($assetId, $quantity)
    {
        $this->dbConnection->query("UPDATE ultra_assets SET num_assets = num_assets - {$quantity} WHERE id = {$assetId}");
    }

    /**
     * Use this to update the Ultra issuance transaction table.
     *
     * @param int $authorityIssuerId Hub authority issuer user id.
     * @param int $assetId ultra asset identifier
     * @param float $quantity
     */
    public function deductAssetQuantityBy($authorityIssuerId, $assetId, $quantity)
    {
        $this->dbConnection->query(<<<SQL
UPDATE `ultra_asset_issuance_history`
    SET `remaining_asset_quantity` = `remaining_asset_quantity` - {$quantity}
WHERE
    `user_id` = {$authorityIssuerId}
    AND `asset_id` = {$assetId}
SQL
        );
    }

    /**
     * Returns the number of assets required for 1 Ven.
     *
     * @param UltraAsset $asset
     * @return Money
     */
    public function getAssetAmountForOneVen(UltraAsset $asset)
    {
        $amount = $this->getAssetValue($asset);
        if ($asset->weightingType() !== self::TYPE_CURRENCY_COMBO && count($asset->weightings()) > 0) {
            $weightings = $asset->weightings();
            $amount = floatval(array_shift($weightings)->currencyAmount());
        }

        return new Money($amount, Currency::custom($asset->tickerSymbol()));
    }

    /**
     * @param UltraAsset $asset
     * @param bool $isFormatted
     * @return float
     */
    private function getAssetValue(UltraAsset $asset, $isFormatted = false)
    {
        $equivalentAssetAmountForOneVen = 0;
        foreach ($asset->weightings() as $weighting) {
            $weightingData = $weighting->toArray();

            $equivalentAssetAmountForOneVen += $weightingData['percentage_amount'];
        }

        if (!$isFormatted) {
            return $equivalentAssetAmountForOneVen;
        }

        $amountParts = explode(self::DECIMAL_SEPARATOR, $equivalentAssetAmountForOneVen);

        // absolute precision value WITHOUT doing any round/ceil/floor
        return floatval(
            $amountParts[0] . self::DECIMAL_SEPARATOR . substr($amountParts[1], 0, 4)
        );
    }

    /**
     * @param UltraAsset $asset
     */
    public function enrichAssetWeightingAmounts(UltraAsset &$asset)
    {
        $currencies = $this->currencyRatesProvider->getByPrimaryCurrencySymbol(Currency::VEN());

        $assetWeightings = array();
        foreach ($asset->weightings() as $weighting) {
            $isWeightingAdded = false;
            foreach ($currencies as $currency) {
                if ($currency->getCurrencyName() !== $weighting->currencyName()) {
                    continue;
                }

                $assetWeightings[] = new UltraAssetWeighting(
                    $weighting->currencyName(),
                    $currency->getRatePerOneVen(),
                    $weighting->percentage()
                );
                $isWeightingAdded = true;
            }

            if (!$isWeightingAdded && strtolower($weighting->currencyName()) == 'ven') {
                $assetWeightings[] = new UltraAssetWeighting(
                    'Ven',
                    $weighting->currencyAmount(),
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

    /**
     * @param int $assetId
     * @param string $selectedConditionIds list of term ids
     * @return int
     */
    public function addAssetTermRelations($assetId, array $selectedConditionIds)
    {
        $insertCount = 0;
        foreach ($selectedConditionIds as $selectedConditionId) {
            $termId = intval($selectedConditionId);
            if ($termId === 0) {
                continue;
            }

            $sql = "INSERT IGNORE INTO `ultra_asset_terms` (`asset_id`, `term_id`) VALUES ({$assetId}, {$termId})";
            $created = $this->dbConnection->query($sql);
            if (!$created) {
                throw new Exception($this->dbConnection->error);
            }
            $insertCount++;
        }

        return $insertCount;
    }

    /**
     * @param int $assetId
     * @return bool
     */
    public function removeAssetTermRelations($assetId)
    {
        return $this->dbConnection->query("DELETE FROM `ultra_asset_terms` WHERE `asset_id` = {$assetId}");
    }

    /**
     * @param int $assetId
     * @return array
     */
    public function getAssetTermRelations($assetId)
    {
        $stmt = $this->dbConnection->query("SELECT * FROM `ultra_asset_terms` AS pivot INNER JOIN `terms_and_conditions` AS t ON (t.id = pivot.term_id) WHERE pivot.`asset_id` = {$assetId}");
        if ($stmt->num_rows === 0) {
            return array();
        }

        $terms = array();
        while ($asset = $stmt->fetch_assoc()) {
            $terms[] = $asset;
        }

        return $terms;
    }

    protected function getWeightingEnrichedUltraAsset(array $asset)
    {
        $assetObj = UltraAssetFactory::fromArray($asset);

        $this->enrichAssetWeightingAmounts($assetObj);

        return $assetObj;
    }

    protected function getUltraAssetRetrievalQuery($filters = array())
    {
        $where = ['1'];
        foreach ($filters as $filterName => $filterValue) {
            if (empty($filterValue)) {
                continue;
            }

            switch($filterName) {
                case 'name':
                    $where[] = "title = '{$filterValue}'";
                    break;
                case 'ticker':
                    $where[] = "ticker_symbol = '{$filterValue}'";
                    break;
                case 'quantitygte':
                    $where[] = "num_assets >= {$filterValue}";
                    break;
                case 'category':
                    $where[] = "category = '{$filterValue}'";
                    break;
            }
        }

        $whereStr = implode(' AND ', $where);
        return <<<SQL
SELECT
    *,
    IF (COUNT(`hash`) > 1, 1, 0) AS `isMergedAsset`,
    SUM(`num_assets`) AS `numAssets`
FROM `ultra_assets`
WHERE
    `is_approved` = 1
    AND `num_assets` > 0
    AND {$whereStr}
GROUP BY `hash`
SQL;
    }
}
