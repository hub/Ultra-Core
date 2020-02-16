<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine\Order;

use InvalidArgumentException;

class SellOrder
{
    /**
     * @var int Order id
     */
    private $id;

    /**
     * @var int Unique Hub Culture selling user identifier
     */
    private $sellerUserId;

    /**
     * @var int A valid asset identifier
     */
    private $assetId;

    /**
     * @var float The rate at which the seller is offering this asset to be sold to a buyer. Aka: ven rate per 1 asset.
     */
    private $offeringRate;

    /**
     * @var float Amount of assets being sold originally. Aka number of units
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
     * SellOrder constructor.
     *
     * @param int    $id                 Order id
     * @param int    $sellerUserId       Unique Hub Culture selling user identifier
     * @param int    $assetId            A valid asset identifier
     * @param float  $offeringRate       The rate at which the seller is offering this asset to be sold to a buyer
     * @param float  $amount             Amount of assets being sold originally. Aka number of units
     * @param float  $settledAmountSoFar Amount of assets bought / matched so far
     * @param string $status             Status of the current sell order.
     *                                   Possible values are: 'pending', 'rejected' or 'processed'
     *
     * @throws InvalidArgumentException
     */
    public function __construct($id, $sellerUserId, $assetId, $offeringRate, $amount, $settledAmountSoFar, $status)
    {
        if (intval($id) === 0) {
            throw new InvalidArgumentException('Invalid order id provided');
        }
        if (intval($sellerUserId) === 0) {
            throw new InvalidArgumentException('Invalid seller user id provided');
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
        $this->sellerUserId = intval($sellerUserId);
        $this->assetId = intval($assetId);
        $this->offeringRate = floatval($offeringRate);
        $this->amount = floatval($amount);
        $this->settledAmountSoFar = floatval($settledAmountSoFar);
        $this->status = $status;
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
    public function getSellerUserId()
    {
        return $this->sellerUserId;
    }

    /**
     * @return int
     */
    public function getAssetId()
    {
        return $this->assetId;
    }

    /**
     * The amount of Ven the seller is willing to earn per one(1) asset.
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
     * @return float
     */
    public function getSettledAmountSoFar()
    {
        return $this->settledAmountSoFar;
    }

    /**
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
     * @return bool
     */
    public function hasSettled()
    {
        return $this->getStatus() === Orders::STATUS_PROCESSED || $this->getStatus() === Orders::STATUS_REJECTED;
    }

    /**
     * @param BuyOrder $buyOrder
     *
     * @return self
     */
    public function match(BuyOrder $buyOrder)
    {
        if ($this->hasSettled()
            // never match orders with different assets. It is like selling YEN to get USD
            // while we are trying to match within the same currency/asset
            || $this->getAssetId() !== $buyOrder->getAssetId()
            // never match the buy and sell orders from the same user account
            || $this->getSellerUserId() === $buyOrder->getBuyerUserId()
            // NOT A MATCH, if this buyer has nothing left to be bought
            || $buyOrder->getAmount() - $buyOrder->getSettledAmountSoFar() <= 0.0
        ) {
            return null;
        }

        if ($this->getOfferingRate() < $buyOrder->getOfferingRate()) {
            return $this;
        }

        return null;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return [
            'id' => $this->getId(),
            'sellerUserId' => $this->getSellerUserId(),
            'assetId' => $this->getAssetId(),
            'offeringRate' => $this->getOfferingRate(),
            'amount' => $this->getAmount(),
            'settledAmountSoFar' => $this->getSettledAmountSoFar(),
            'status' => $this->getStatus(),
        ];
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return sprintf('SellOrder:[]', json_encode($this->toArray()));
    }
}
