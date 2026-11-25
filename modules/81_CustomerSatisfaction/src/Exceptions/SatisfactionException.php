<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the customer satisfaction module was refused. The `code` is a translation key (lang
 * customer_satisfaction.errors.<code> and customerSatisfaction.error.<code> in the frontend dictionary). Rendered as an RFC 7807 problem.
 */
final class SatisfactionException extends RuntimeException
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
            'detail' => __('customer_satisfaction.errors.'.$this->errorCode, array_filter($this->params, 'is_scalar')),
            'code' => $this->errorCode,
            'params' => $this->params,
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
