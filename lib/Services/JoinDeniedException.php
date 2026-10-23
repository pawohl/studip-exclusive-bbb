<?php

namespace ExclusiveBBB\Services;

/**
 * A join was refused; the code is one of the JoinService::DENY_* constants.
 */
class JoinDeniedException extends \RuntimeException
{
    public string $reason;

    public function __construct(string $reason)
    {
        parent::__construct($reason);
        $this->reason = $reason;
    }
}
