<?php
/**
 * @author  Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 */

namespace Hub\UltraCore\MatchEngine\Order;

use InvalidArgumentException;

class BuyOrder
{
    /**
     * @var int Order id
     */
    private $id;

    /**
     * @var int Unique Hub Culture buying user identifier
     */
    private $buyerUserId;

    /**
     * @var int A valid asset identifier
     */
    private $assetId;

    /**
     * @var float This is the Ven rate which this buyer is willing to use to buy 1 Asset
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
     * BuyOrder constructor.
     *
     * @param int    $id                 Order id
     * @param int    $buyerUserId        Unique Hub Culture buying user identifier
     * @param int    $assetId            A valid asset identifier
     * @param float  $offeringRate       The rate at which the buyer is willing to buy the given asset from a seller
     * @param float  $amount             Amount of assets being bought originally. Aka number of units
     * @param float  $settledAmountSoFar Amount of assets bought / matched so far
     * @param string $status             Status of the current sell order.
     *                                   Possible values are: 'pending', 'rejected' or 'processed'
     *
     */
    public function __construct($id, $buyerUserId, $assetId, $offeringRate, $amount, $settledAmountSoFar, $status)
    {
        if (intval($id) === 0) {
            throw new InvalidArgumentException('Invalid order id provided');
        }
        if (intval($buyerUserId) === 0) {
            throw new InvalidArgumentException('Invalid buyer user id provided');
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
        $this->buyerUserId = intval($buyerUserId);
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
    public function getBuyerUserId()
    {
        return $this->buyerUserId;
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
     * @param SellOrder $sellOrder
     *
     * @return self
     */
    public function match(SellOrder $sellOrder)
    {
        if ($this->hasSettled()
            // never match orders with different assets. It is like selling YEN to get USD
            // while we are trying to match within the same currency/asset
            || $this->getAssetId() !== $sellOrder->getAssetId()
            // never match the buy and sell orders from the same user account
            || $this->getBuyerUserId() === $sellOrder->getSellerUserId()
            // NOT A MATCH, if this seller has nothing left to sell
            || $sellOrder->getAmount() - $sellOrder->getSettledAmountSoFar() <= 0.0
        ) {
            return null;
        }

        if ($this->getOfferingRate() > $sellOrder->getOfferingRate()) {
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
            'buyerUserId' => $this->getBuyerUserId(),
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
        return sprintf('BuyOrder:[]', json_encode($this->toArray()));
    }
}
