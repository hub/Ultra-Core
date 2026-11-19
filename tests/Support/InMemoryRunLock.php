<?php

namespace Hub\UltraCore\Support;

use Hub\UltraCore\MatchEngine\Settlement\RunLock;

/**
 * Stands in for GET_LOCK: several instances sharing one $state behave like several processes and one lock name.
 */
class InMemoryRunLock implements RunLock
{
    /** @var \ArrayObject */
    private $state;

    private $held = false;

    public function __construct(\ArrayObject $state)
    {
        $this->state = $state;
    }

    public function acquire()
    {
        if (!empty($this->state['held'])) {
            return false;
        }
        $this->state['held'] = true;
        $this->held = true;

        return true;
    }

    public function release()
    {
        if ($this->held) {
            $this->state['held'] = false;
            $this->held = false;
        }
    }
}
