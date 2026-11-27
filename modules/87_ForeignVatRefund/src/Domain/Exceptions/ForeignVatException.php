<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Domain error of the foreign VAT refund module. The code is stable, translated by the client (foreignVat.error.<code>) and by the API
 * (lang foreign_vat_refund.errors.<code>). Rendered as an RFC 7807 problem.
 */
final class ForeignVatException extends RuntimeException
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

    public function render(Request $request): JsonResponse
    {
        $key = 'foreign_vat_refund.errors.'.$this->errorCode;

        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->httpStatus,
            'title' => $this->errorCode,
            'status' => $this->httpStatus,
            'detail' => Lang::has($key) ? __($key, array_map('strval', array_filter($this->context, 'is_scalar'))) : $this->errorCode,
            'code' => $this->errorCode,
        ] + ($this->context === [] ? [] : ['context' => $this->context]), $this->httpStatus, ['Content-Type' => 'application/problem+json']);
    }
}
