<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Business-rule failure rendered as RFC 7807 Problem Details. The `code` is a stable key; the frontend shows the
 * seeded text registered for it.
 */
final class MarketingAutomationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $context = [])
    {
        parent::__construct($errorCode);
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self('invalid_transition', 409, ['from' => $from, 'to' => $to]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function validation(string $code, array $context = []): self
    {
        return new self($code, 422, $context);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'https://fatturafacile.example/problems/marketing-automation/'.$this->errorCode,
            'title' => $this->errorCode,
            'status' => $this->status,
            'code' => $this->errorCode,
            'context' => $this->context,
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
