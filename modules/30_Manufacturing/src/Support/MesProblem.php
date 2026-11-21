<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Support;

use Illuminate\Http\JsonResponse;

/**
 * RFC 9457 problem details of the MES integration endpoints. `code` is the stable machine-readable error, `retryable`
 * tells the MES Core outbox whether sending the same request again can succeed.
 */
final class MesProblem
{
    private const string TypeBase = 'https://errors.fatturafacile.example/mes/';

    /**
     * The problem document.
     *
     * @param  list<array{pointer: string, message: string}>  $errors
     * @return array<string, mixed>
     */
    public static function document(int $status, string $code, string $title, ?string $detail = null, bool $retryable = false, array $errors = [], ?string $instance = null): array
    {
        return array_filter([
            'type' => self::TypeBase.$code,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $instance,
            'code' => 'mes.'.$code,
            'retryable' => $retryable,
            'errors' => $errors === [] ? null : $errors,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * The problem as an `application/problem+json` response.
     *
     * @param  list<array{pointer: string, message: string}>  $errors
     */
    public static function response(int $status, string $code, string $title, ?string $detail = null, bool $retryable = false, array $errors = []): JsonResponse
    {
        return response()->json(
            self::document($status, $code, $title, $detail, $retryable, $errors, request()->getRequestUri()),
            $status,
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
