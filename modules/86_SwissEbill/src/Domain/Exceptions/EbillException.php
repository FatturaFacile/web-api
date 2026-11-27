<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Base of the domain errors of the eBill module. The code is stable and translated by the client (swissEbill.error.<code>) and by
 * the API (lang swiss_ebill.errors.<code>). Rendered as an RFC 7807 problem.
 */
class EbillException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message = '', private readonly int $httpStatus = 422)
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * Machine readable extras of the problem (the violations of a payload, the states of a refused transition).
     *
     * @return array<string, mixed>
     */
    protected function extensions(): array
    {
        return [];
    }

    public function render(Request $request): JsonResponse
    {
        $key = 'swiss_ebill.errors.'.$this->errorCode;

        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->httpStatus,
            'title' => $this->errorCode,
            'status' => $this->httpStatus,
            'detail' => Lang::has($key) ? __($key) : $this->getMessage(),
            'code' => $this->errorCode,
        ] + $this->extensions(), $this->httpStatus, ['Content-Type' => 'application/problem+json']);
    }
}
