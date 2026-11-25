<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the franchise network module was refused. The `code` is a translation key (lang
 * franchise_network.errors.<code> and franchiseNetwork.error.<code> in the frontend dictionary). Rendered as an RFC 7807 problem.
 */
final class FranchiseNetworkException extends RuntimeException
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

    /**
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => $this->errorCode,
            'status' => $this->status,
            'detail' => __('franchise_network.errors.'.$this->errorCode, array_filter($this->params, 'is_scalar')),
            'code' => $this->errorCode,
            'params' => $this->params,
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
