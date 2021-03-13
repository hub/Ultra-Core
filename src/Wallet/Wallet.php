<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
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
     * @var string This is a unique identifier which can be used to identify a wallet.
     */
    private $publicKey;

    /**
     * Wallet constructor.
     *
     * @param int    $id               Wallet identifier
     * @param int    $userId           Identifier of the user this wallet belongs to
     * @param int    $assetId          Identifier of the wallet's asset / currency
     * @param float  $balance          wallet balance
     * @param float  $availableBalance Available balance after any pending transaction which is the real balance
     * @param string $publicKey        Wallet's public key. This is another identifier of the wallet which can be used
     *                                 publicly.
     */
    public function __construct($id, $userId, $assetId, $balance, $availableBalance, $publicKey)
    {
        $this->id = $id;
        $this->userId = $userId;
        $this->assetId = $assetId;
        $this->balance = $balance;
        $this->availableBalance = $availableBalance;
        $this->publicKey = $publicKey;
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

    /**
     * @return string
     */
    public function getPublicKey()
    {
        return $this->publicKey;
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return array(
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'assetId' => $this->getAssetId(),
            'balance' => $this->getBalance(),
            'availableBalance' => $this->getAvailableBalance(),
            'publicKey' => $this->getPublicKey(),
        );
    }
}
