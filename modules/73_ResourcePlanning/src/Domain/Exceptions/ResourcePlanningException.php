<?php

declare(strict_types=1);

namespace Modules\ResourcePlanning\Domain\Exceptions;

use RuntimeException;

/**
 * A business rule of the planner was violated. The code is a stable identifier the clients translate; the parameters are
 * the values to put in the message.
 */
class ResourcePlanningException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public function __construct(public readonly string $errorCode, string $message = '', public readonly array $params = [])
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    /**
     * @return array{code: string, params: array<string, scalar|null>}
     */
    public function toIssue(): array
    {
        return ['code' => $this->errorCode, 'params' => $this->params];
    }
}
