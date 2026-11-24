<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the agent commission module was refused. The `code` is a translation key
 * (lang agent_commissions.errors.<code>, and agentCommissions.error.<code> in the frontend dictionary).
 * Rendered as an RFC 7807 problem.
 */
class AgentCommissionException extends RuntimeException
{
    public function __construct(string $message, private readonly string $errorCode = 'refused', private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function render(Request $request): JsonResponse
    {
        $key = 'agent_commissions.errors.'.$this->errorCode;
        $detail = __($key) === $key ? $this->getMessage() : __($key);

        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => $this->errorCode,
            'status' => $this->status,
            'detail' => $detail,
            'instance' => $request->getRequestUri(),
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
