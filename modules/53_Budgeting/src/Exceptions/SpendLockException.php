<?php

declare(strict_types=1);

namespace Modules\Budgeting\Exceptions;

use Illuminate\Http\JsonResponse;
use Modules\Budgeting\Domain\SpendDecision;
use RuntimeException;

/**
 * The budget does not allow the spend (hard block) and no approved override covers it. Rendered as a 422 on the
 * budget field with the figures the requester needs to ask for an override.
 */
final class SpendLockException extends RuntimeException
{
    public function __construct(string $message, public readonly SpendDecision $decision)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['budget' => [$this->getMessage()]],
            'spend_control' => $this->decision->toArray(),
        ], 422);
    }
}
