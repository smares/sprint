<?php

namespace App\Services;

use RuntimeException;

/**
 * Asana did not answer as needed (wrong token, no access, still unreachable after some retries) or the import cannot
 * go on as asked. The message is meant to be read by the person running the import.
 */
class AsanaException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message);
    }
}
