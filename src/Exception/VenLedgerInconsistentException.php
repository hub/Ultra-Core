<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Exception;

/**
 * A leg failed and the Ven could not be put back, or the outcome of the write is unknown (a failed commit or
 * rollback). Logged CRITICAL with record() holding what is needed to repair it by hand.
 *
 * Default to safety: callers must not retry, refund or reverse anything on their own. Stop and flag for review.
 */
class VenLedgerInconsistentException extends VenLegFailedException
{
    public function isUncertain()
    {
        return true;
    }
}
