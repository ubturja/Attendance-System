<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Domain failure for HRMS messaging. Controllers map this to the JSON envelope.
 */
class MessagingException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
