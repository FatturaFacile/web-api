<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Exceptions;

use RuntimeException;

/**
 * An MES event that is well formed but cannot be applied (unknown machine, order of another tenant...). The MES Core
 * must not send it again: it is dead-lettered on its side.
 */
final class MesEventRejectedException extends RuntimeException
{
    public function __construct(string $message, public readonly string $problemCode, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
