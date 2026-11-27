<?php

declare(strict_types=1);

namespace Modules\Subcontracting\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the subcontracting module was refused. The `code` is a translation key (lang subcontracting.errors.<code>
 * and subcontracting.error.<code> in the frontend dictionary). Rendered as an RFC 7807 problem.
 */
final class SubcontractingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(private readonly string $errorCode, private readonly int $status = 422, private readonly array $params = [])
    {
        parent::__construct($errorCode);
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
        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => $this->errorCode,
            'status' => $this->status,
            'detail' => __('subcontracting.errors.'.$this->errorCode, array_filter($this->params, 'is_scalar')),
            'code' => $this->errorCode,
            'params' => $this->params,
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
