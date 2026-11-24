<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A rule of the accounts payable module was violated. The error code is a stable identifier translated through the
 * `accounts_payable.errors.*` lang keys, in the four dictionaries (it, de, fr, en).
 */
final class AccountsPayableException extends RuntimeException
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    private function __construct(public readonly string $errorCode, public readonly int $status, public readonly array $params = [])
    {
        parent::__construct(__('accounts_payable.errors.'.$errorCode, $params));
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function invalid(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_UNPROCESSABLE_ENTITY, $params);
    }

    /**
     * The state of the record does not allow the operation.
     *
     * @param  array<string, scalar|null>  $params
     */
    public static function conflict(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_CONFLICT, $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function forbidden(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_FORBIDDEN, $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function notFound(string $code, array $params = []): self
    {
        return new self($code, Response::HTTP_NOT_FOUND, $params);
    }

    /**
     * RFC 7807 problem details with the stable code (and its parameters) in invalid_params, so every client can translate it.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => 'Accounts Payable Rule Violation',
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
