<?php
/**
 * @author        Tharanga Kothalawala <tharanga.kothalawala@gmail.com>
 * @copyright (c) 2019 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Ven;

/**
 * This represent the main Hub Culture Ven wallet.
 *
 * @package Hub\UltraCore\Ven
 */
class VenWallet
{
    /**
     * @var int
     */
    private $userId;

    /**
     * @var string
     */
    private $balance;

    /**
     * @param int    $userId  Hub Culture user identifier.
     * @param string $balance Ven balance of the user
     */
    public function __construct($userId, $balance)
    {
        $this->userId = $userId;
        $this->balance = $balance;
    }

    /**
     * @return int
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * @return string
     */
    public function getBalance()
    {
        return $this->balance;
    }
}
