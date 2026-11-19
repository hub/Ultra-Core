<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\Exception;

/**
 * The sender was debited, then the receipt or the receiver's credit failed. The debit was reversed (rolled back,
 * or credited back), so no Ven moved. Safe to treat as "nothing happened".
 */
class VenLegFailedException extends VenTransferException
{
}
