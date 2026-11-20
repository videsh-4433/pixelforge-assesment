<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientCreditsException extends RuntimeException
{
    public function __construct(public readonly int $requiredNano, public readonly int $availableNano)
    {
        parent::__construct('Not enough credits for this generation.');
    }
}
