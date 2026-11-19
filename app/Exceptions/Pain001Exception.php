<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Raised when a payment file cannot be produced: a payment breaks the Swiss QR-IBAN / reference rules, or the
 * generated XML does not validate against the ISO 20022 schema.
 */
class Pain001Exception extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
