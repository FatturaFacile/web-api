<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Domain error of the bonded warehouse module. The code is stable, translated by the client (bondedWarehouses.error.<code>) and by the API
 * (lang bonded_warehouses.errors.<code>). Rendered as an RFC 7807 problem.
 */
final class BondedWarehouseException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(private readonly string $errorCode, private readonly array $context = [], private readonly int $httpStatus = 422)
    {
        parent::__construct($errorCode);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function because(string $code, array $context = [], int $status = 422): self
    {
        return new self($code, $context, $status);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function render(Request $request): JsonResponse
    {
        $key = 'bonded_warehouses.errors.'.$this->errorCode;

        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->httpStatus,
            'title' => $this->errorCode,
            'status' => $this->httpStatus,
            'detail' => Lang::has($key) ? __($key, array_map('strval', array_filter($this->context, 'is_scalar'))) : $this->errorCode,
            'code' => $this->errorCode,
        ] + ($this->context === [] ? [] : ['context' => $this->context]), $this->httpStatus, ['Content-Type' => 'application/problem+json']);
    }
}
