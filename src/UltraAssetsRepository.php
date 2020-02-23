<?php
/**
 * @author : Tharanga Kothalawala <tharanga.kothalawala@tsk-webdevelopment.com>
 * @date   : 03-06-2018
 */

namespace Hub\UltraCore;

use Hub\UltraCore\Money\Currency;
use Hub\UltraCore\Money\Money;
use Exception;
use mysqli;

class UltraAssetsRepository
{
    const CURRENCY_CODE_VEN = 'VEN';
    const CURRENCY_CODE_VEN_LABEL = 'Ven';
    const DECIMAL_SEPARATOR = '.';
    const TYPE_CURRENCY_COMBO = 'currency_combination';
    const TYPE_VEN_AMOUNT = 'custom_ven_amount';
    const TYPE_EXTERNAL_ENTITY = 'external_entity_with_description';
    const WITHDRAWAL_VEN_FEE = 350;
    const EXCHANGE_PERCENT_FEE = 1;

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
     * @param mysqli                         $dbConnection
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
     * @return UltraAsset[]
     */
    public function getAllActiveAssets()
    {
        /** @var \mysqli_result $stmt */
        $stmt = $this->dbConnection->query($this->getUltraAssetRetrievalQuery());
        if ($stmt->num_rows === 0) {
            return array();
        }

        $assets = array();
        while ($asset = $stmt->fetch_assoc()) {
            $assets[] = $this->getWeightingEnrichedUltraAsset($asset);
        }

        return $assets;
    }

    /**
     * @param int $assetId Ultra asset unique identifier.
     *
     * @return UltraAsset|null
     */
    public function getAssetById($assetId)
    {
        if (intval($assetId) === 0) {
            return null;
        }

        /** @var \mysqli_result $stmt */
        $stmt = $this->dbConnection->query("SELECT * FROM ultra_assets WHERE id = {$assetId}");
        if ($stmt->num_rows === 0) {
            return null;
        }

        return $this->getWeightingEnrichedUltraAsset($stmt->fetch_assoc());
    }

    /**
     * @param string $ticker unique ticker symbol of a ultra asset. ex: uBMD
     *
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
     * @param int   $assetId
     * @param float $quantity
     */
    public function deductTotalAssetQuantityBy($assetId, $quantity)
    {
        $this->dbConnection->query("UPDATE ultra_assets SET num_assets = num_assets - {$quantity} WHERE id = {$assetId}");
    }

    /**
     * Use this to update the Ultra issuance transaction table.
     *
     * @param int   $authorityIssuerId Hub authority issuer user id.
     * @param int   $assetId           Ultra asset unique identifier.
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
     *
     * @return Money
     */
    public function getAssetAmountForOneVen(UltraAsset $asset)
    {
        $amount = $this->getAssetValue($asset);
        if ($asset->weightingType() !== self::TYPE_CURRENCY_COMBO && count($asset->weightings()) > 0) {
            $weightings = $asset->weightings();
            /**
             * If we are here, this means the weighting type is based on Ven.
             * Let's divide 1 Ven by the custom Ven amount to get the asset amount.
             * @see UltraAssetsRepository::$availableWeightingTypes
             */
            $amount = 1 / floatval(array_shift($weightings)->currencyAmount());
        }

        return new Money($amount, Currency::custom($asset->tickerSymbol()));
    }

    /**
     * @param UltraAsset $asset
     * @param bool       $isFormatted
     *
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
                    self::CURRENCY_CODE_VEN_LABEL,
                    $weighting->currencyAmount(),
                    $weighting->percentage()
                );
            }
        }

        $asset->setWeightings($assetWeightings);
    }

    /**
     * @param int   $assetId              Ultra asset unique identifier.
     * @param int[] $selectedConditionIds list of term ids
     *
     * @return int
     * @throws Exception
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
     * @param int $assetId Ultra asset unique identifier.
     *
     * @return bool
     */
    public function removeAssetTermRelations($assetId)
    {
        return $this->dbConnection->query("DELETE FROM `ultra_asset_terms` WHERE `asset_id` = {$assetId}");
    }

    /**
     * @param int $assetId Ultra asset unique identifier.
     *
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

    /**
     * Use this to log the quantity of a new ultra asset launched by an authority user.
     *
     * This stores a pending record until a super admin reviews and approves the launch later.
     * @see UltraAssetsRepository::approveIssuance()
     *
     * @param int   $assetId            Ultra asset unique identifier.
     * @param int   $issuingUserId      Issuing user's id who is having 'authority level' membership.
     * @param float $issuingNewQuantity Issuing asset quantity
     */
    public function logUltraAssetLaunch($assetId, $issuingUserId, $issuingNewQuantity)
    {
        $this->dbConnection->query(<<<SQL
            INSERT INTO `ultra_asset_issuance_history`
            (`user_id`, `asset_id`, `pending_new_quantity`, `created_at`)
            VALUES (
                {$issuingUserId},
                {$assetId},
                {$issuingNewQuantity},
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                `pending_new_quantity` = `pending_new_quantity` + VALUES(`pending_new_quantity`),
                `updated_at` = NOW()
SQL
        );
    }

    /**
     * Use this to approve a pending ultra asset launch quantity by an authority user.
     * Only call this as a super admin.
     *
     * @param int $assetId       Ultra asset unique identifier.
     * @param int $issuingUserId Issuing user's id who is having 'authority level' membership.
     *
     * @return bool
     */
    public function approveIssuance($assetId, $issuingUserId)
    {
        $stmt = $this->dbConnection->prepare(
            "SELECT `pending_new_quantity` FROM `ultra_asset_issuance_history` WHERE `user_id` = ? AND `asset_id` = ?"
        );
        $stmt->bind_param("ii", $issuingUserId, $assetId);
        $executed = $stmt->execute();
        if (!$executed) {
            return false;
        }

        $stmt->bind_result($pendingNewQuantity);
        $stmt->fetch();
        if (floatval($pendingNewQuantity) <= 0.0) {
            return true;
        }
        $stmt->free_result();

        $this->dbConnection->begin_transaction();
        $stmt = $this->dbConnection->prepare(<<<SQL
            UPDATE `ultra_asset_issuance_history`
            SET
                `original_quantity_issued` = `original_quantity_issued` + {$pendingNewQuantity},
                `remaining_asset_quantity` = `remaining_asset_quantity` + {$pendingNewQuantity},
                `pending_new_quantity` = 0.0,
                `updated_at` = NOW()
            WHERE
                `user_id` = ?
                AND `asset_id` = ?
SQL
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ii', $issuingUserId, $assetId);
        $stmt->execute();
        $stmt->free_result();

        $stmt = $this->dbConnection->prepare(
            "UPDATE `ultra_assets` SET `num_assets` = `num_assets` + {$pendingNewQuantity}, `is_approved` = 1, `updated_at` = NOW() WHERE `id` = ?"
        );
        if (!$stmt) {
            $this->dbConnection->rollback();
            return false;
        }

        $stmt->bind_param('i', $assetId);
        $stmt->execute();

        return $this->dbConnection->commit();
    }

    /**
     * Use this as a super admin user to deny a pending ultra issuance and to remove the issuance pending record amount.
     *
     * @param int $assetId       Ultra asset unique identifier.
     * @param int $issuingUserId Issuing user's id who is having 'authority level' membership.
     *
     * @return bool
     */
    public function denyIssuance($assetId, $issuingUserId)
    {
        $stmt = $this->dbConnection->prepare("UPDATE `ultra_asset_issuance_history` SET `pending_new_quantity` = 0.0 WHERE `user_id` = ? AND `asset_id` = ?");
        if ($stmt) {
            $stmt->bind_param('ii', $issuingUserId, $assetId);
            return $stmt->execute();
        }

        return false;
    }

    /**
     * Use this to hard delete an unused Ultra asset. This first checks for any usages and only deletes if none.
     * Because deleting the assets while they are in use is wrong and will be similar banks rejecting USDs from people
     * who already have them telling USD is no longer identified as a valid currency.
     *
     * @param int $assetId Ultra asset unique identifier.
     *
     * @throws \RuntimeException
     */
    public function deleteUltraAsset($assetId)
    {
        $stmt = $this->dbConnection->prepare(
            "SELECT COUNT(1) AS `usage_count` FROM `wallets` WHERE `asset_id` = ?"
        );
        $stmt->bind_param("i", $assetId);
        $stmt->execute();
        $stmt->bind_result($walletsInUse);
        $stmt->fetch();
        if (intval($walletsInUse) > 0) {
            throw new \RuntimeException('The asset cannot be deleted as it is already in use');
        }
        $stmt->free_result();

        $this->dbConnection->begin_transaction();
        $stmt = $this->dbConnection->prepare('DELETE FROM `ultra_asset_issuance_history` WHERE `asset_id` = ?');
        $stmt->bind_param('i', $assetId);
        $deleted = $stmt->execute();
        if (!$deleted) {
            throw new \RuntimeException('Error deleting the ultra launch history');
        }

        $stmt = $this->dbConnection->prepare('DELETE FROM `ultra_assets` WHERE `id` = ?');
        $stmt->bind_param('i', $assetId);
        $deleted = $stmt->execute();
        if (!$deleted) {
            $this->dbConnection->rollback();
            throw new \RuntimeException('Error deleting the ultra asset');
        }

        $this->dbConnection->commit();
    }

    /**
     * @param array $asset
     *
     * @return UltraAsset
     */
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

            switch ($filterName) {
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
    *
FROM `ultra_assets`
WHERE
    `is_approved` = 1
    AND {$whereStr}
SQL;
    }
}
