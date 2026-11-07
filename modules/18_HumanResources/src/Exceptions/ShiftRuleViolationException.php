<?php

declare(strict_types=1);

namespace Modules\HumanResources\Exceptions;

use DomainException;
use Illuminate\Http\JsonResponse;
use Modules\HumanResources\Services\Shifts\ShiftViolation;

/**
 * The plan breaks the working time rules and nobody acknowledged it.
 */
final class ShiftRuleViolationException extends DomainException
{
    /**
     * @param  list<ShiftViolation>  $violations
     */
    public function __construct(public readonly array $violations)
    {
        parent::__construct('Il piano viola le regole sul tempo di lavoro: correggerlo o confermare le violazioni indicando il motivo.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['violations' => array_map(static fn (ShiftViolation $violation): array => $violation->toArray(), $this->violations)],
        ], 422);
    }
}
