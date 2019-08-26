<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@hubculture.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Wallet;

class Wallet
{
    /**
     * @var int
     */
    private $id;

    /**
     * @var int
     */
    private $userId;

    /**
     * @var int
     */
    private $assetId;

    /**
     * @var float
     */
    private $balance;

    /**
     * @var float
     */
    private $availableBalance;

    /**
     * Wallet constructor.
     *
     * @param int   $id
     * @param int   $userId
     * @param int   $assetId
     * @param float $balance
     * @param float $availableBalance
     */
    public function __construct($id, $userId, $assetId, $balance, $availableBalance)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->assetId = $assetId;
        $this->balance = $balance;
        $this->availableBalance = $availableBalance;
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
     * @return float
     */
    public function getBalance()
    {
        return $this->balance;
    }

    /**
     * @return float
     */
    public function getAvailableBalance()
    {
        return $this->availableBalance;
    }
}
