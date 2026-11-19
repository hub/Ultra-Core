<?php
/**
 * @copyright (c) 2026 by HubCulture Ltd.
 */

namespace Hub\UltraCore\MatchEngine\Settlement;

/**
 * Keeps match engine runs from overlapping.
 */
interface RunLock
{
    /**
     * @return bool true when this process now holds the lock; false when another run holds it or it can't be taken
     */
    public function acquire();

    /**
     * @return void
     */
    public function release();
}
