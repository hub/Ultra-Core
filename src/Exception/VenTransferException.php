<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Exception;

use RuntimeException;

/**
 * A Ven transfer that was refused, or that could not be completed.
 *
 * Thrown by UltraVenRepository::sendVen. It extends RuntimeException because existing callers catch that.
 * reason() is one of the constants below (the same names as hub/Hub's VenLedgerException and the Ven API codes).
 *
 * The class says how much moved:
 *   VenTransferException            refused before any Ven moved
 *   VenLegFailedException           a leg failed after the debit; the debit was reversed, so nothing moved
 *   VenLedgerInconsistentException  a leg failed and the reversal failed too, or the outcome is unknown:
 *                                   logged CRITICAL with a recoverable record. Never retry; a human must look.
 */
class VenTransferException extends RuntimeException
{
    const INVALID_AMOUNT = 'invalid_amount';
    const SELF_TRANSFER = 'self_transfer';
    const NO_ACCOUNT = 'no_account';
    const INSUFFICIENT_FUNDS = 'insufficient_funds';
    const LEDGER_ERROR = 'ledger_unavailable';
    const RECEIPT_FAILED = 'receipt_failed';
    const CREDIT_FAILED = 'credit_failed';
    const INCONSISTENT = 'inconsistent';

    /** @var string */
    private $reason;

    /** @var array */
    private $record;

    /**
     * @param string          $message
     * @param string          $reason   one of the constants
     * @param array           $record   what was attempted (from, to, amount, transaction id, ...)
     * @param \Throwable|null $previous
     */
    public function __construct($message, $reason, array $record = array(), $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->reason = (string)$reason;
        $this->record = $record;
    }

    /**
     * @return string
     */
    public function reason()
    {
        return $this->reason;
    }

    /**
     * @return array
     */
    public function record()
    {
        return $this->record;
    }

    /**
     * @return bool true when some Ven may have moved and nobody knows exactly what: stop, don't retry
     */
    public function isUncertain()
    {
        return false;
    }
}
