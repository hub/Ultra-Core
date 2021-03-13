<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 */

namespace Hub\UltraCore\MatchEngine\Order;

use InvalidArgumentException;

abstract class AbstractOrder
{
    /**
     * @var int Order id
     */
    private $id;

    /**
     * @var int Unique Hub Culture user identifier
     */
    private $userId;

    /**
     * @var int A valid asset identifier
     */
    private $assetId;

    /**
     * @var float This is the Ven rate which a buyer or a seller in offering
     */
    private $offeringRate;

    /**
     * @var float Amount of assets being bought originally. Aka number of units
     */
    private $amount;

    /**
     * @var float Amount of assets bought / matched so far
     */
    private $settledAmountSoFar;

    /**
     * @var string Status of the current sell order.
     */
    private $status;

    /**
     * @var int Number of times that we have tried to match a order for.
     */
    private $numMatchAttempts;

    /**
     * BuyOrder constructor.
     *
     * @param int    $id                 Order id
     * @param int    $userId             Unique Hub Culture user identifier related to this buy/sell order.
     * @param int    $assetId            A valid asset identifier
     * @param float  $offeringRate       The rate at which the buyer is willing to buy the given asset from a seller.
     *                                   Or the rate at which the seller is offering this asset to be sold to a buyer
     * @param float  $amount             Amount of assets being bought originally. Aka number of units
     *                                   Or the amount of assets being sold originally.
     * @param float  $settledAmountSoFar Amount of assets bought or sold (matched) so far
     * @param string $status             Status of the current sell order.
     *                                   Possible values are: 'pending', 'rejected' or 'processed'
     *
     * @param int    $numMatchAttempts   [optional] Number of times that we have tried to match a order for.
     */
    public function __construct(
        $id,
        $userId,
        $assetId,
        $offeringRate,
        $amount,
        $settledAmountSoFar,
        $status,
        $numMatchAttempts = 0
    ) {
        if (intval($id) < 0) {
            throw new InvalidArgumentException('Invalid order id provided');
        }
        if (intval($userId) === 0) {
            throw new InvalidArgumentException('Invalid user id provided');
        }
        if (intval($assetId) === 0) {
            throw new InvalidArgumentException('Invalid asset id provided');
        }
        if (floatval($offeringRate) <= 0.0) {
            throw new InvalidArgumentException('Invalid offering rate is provided. Rate must be grater than zero(0)');
        }
        if (floatval($amount) === 0.0) {
            throw new InvalidArgumentException('Invalid amount provided');
        }

        $this->id = intval($id);
        $this->userId = intval($userId);
        $this->assetId = intval($assetId);
        $this->offeringRate = floatval($offeringRate);
        $this->amount = floatval($amount);
        $this->settledAmountSoFar = floatval($settledAmountSoFar);
        $this->status = $status;
        $this->numMatchAttempts = intval($numMatchAttempts);
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return int
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * @return int
     */
    public function getAssetId()
    {
        return $this->assetId;
    }

    /**
     * The amount of Ven the buyer is willing to pay per one(1) asset.
     * ex: 1 uUSD = 9.9309 Ven
     *
     * Or The amount of Ven the seller is willing to earn per one(1) asset.
     * Ex: 9.9309 Ven = 1 uUSD
     *
     * @return float
     */
    public function getOfferingRate()
    {
        return $this->offeringRate;
    }

    /**
     * @return float
     */
    public function getAmount()
    {
        return $this->amount;
    }

    /**
     * Returns the amount that the matching engine have matched and settled so far.
     *
     * @return float
     */
    public function getSettledAmountSoFar()
    {
        return $this->settledAmountSoFar;
    }

    /**
     * Use this to set the amount that the matching engine have matched and settled so far off of the original amount
     * @see AbstractOrder::amount
     *
     * @param float $settledAmountSoFar
     */
    public function setSettledAmountSoFar($settledAmountSoFar)
    {
        $this->settledAmountSoFar = floatval($settledAmountSoFar);
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param string $status       Status of the current sell order.
     *                             Possible values are: 'pending', 'rejected' or 'processed'
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * @return int
     */
    public function getNumMatchAttempts()
    {
        return $this->numMatchAttempts;
    }

    /**
     * @return bool
     */
    public function hasSettled()
    {
        return $this->getStatus() === Orders::STATUS_PROCESSED || $this->getStatus() === Orders::STATUS_REJECTED;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'id' => $this->getId(),
            'buyerUserId' => $this->getUserId(),
            'assetId' => $this->getAssetId(),
            'offeringRate' => $this->getOfferingRate(),
            'amount' => $this->getAmount(),
            'settledAmountSoFar' => $this->getSettledAmountSoFar(),
            'status' => $this->getStatus(),
            'numMatchAttempts' => $this->getNumMatchAttempts(),
        ];
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return json_encode($this->toArray());
    }

    /**
     * Use this to get a pending order of any kind.
     *
     * @param int   $userId              Unique Hub Culture user identifier related to this buy/sell order.
     * @param int   $assetId             A valid asset identifier
     * @param float $offeringRate        The rate at which the buyer is willing to buy the given asset from a seller.
     *                                   Or the rate at which the seller is offering this asset to be sold to a buyer
     * @param float $amount              Amount of assets being bought originally. Aka number of units
     *                                   Or the amount of assets being sold originally.
     *
     * @return AbstractOrder|BuyOrder|SellOrder
     */
    public static function newPendingOrder($userId, $assetId, $offeringRate, $amount)
    {
        return new static(
            0,
            $userId,
            $assetId,
            $offeringRate,
            $amount,
            0,
            Orders::STATUS_PENDING,
            0
        );
    }

    /**
     * This matches a given buy and sell order pair.
     *
     * @param AbstractOrder $order This can be a sell or a buy order
     *
     * @return self
     */
    public abstract function match(AbstractOrder $order);
}
