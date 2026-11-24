<?php

declare(strict_types=1);

namespace Modules\SwissVat\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Domain exception for the Swiss VAT compliance module (Mod 024).
 * Uses stable error codes translated across it, de, fr, en via `swiss_vat.errors.*`.
 */
final class SwissVatException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $params = [],
    ) {
        parent::__construct(__('swiss_vat.errors.'.$errorCode, $params));
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function invalid(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_UNPROCESSABLE_ENTITY, $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function locked(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_CONFLICT, $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function notFound(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_NOT_FOUND, $params);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => 'Swiss VAT Rule Violation',
            'status' => $this->status,
            'detail' => $this->getMessage(),
            'instance' => request()->getRequestUri(),
            'invalid_params' => [$this->toIssue()],
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * @return array{code: string, params: array<string, scalar|null>}
     */
    public function toIssue(): array
    {
        return ['code' => $this->errorCode, 'params' => $this->params];
    }
}
