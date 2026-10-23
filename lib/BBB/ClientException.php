<?php

namespace ExclusiveBBB\BBB;

/**
 * Raised when a BigBlueButton API call fails on transport or returns FAILED.
 */
class ClientException extends \RuntimeException
{
    /** @var string|null BBB messageKey, e.g. "notFound" */
    public $messageKey;

    public function __construct(string $message, ?string $messageKey = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->messageKey = $messageKey;
    }
}
