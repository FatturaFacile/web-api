<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the industrial laundry module was refused. The `code` is a translation key (lang industrial_laundry.errors.<code>
 * and industrialLaundry.error.<code> in the frontend dictionary). Rendered as an RFC 7807 problem.
 */
final class IndustrialLaundryException extends RuntimeException
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
        $key = 'industrial_laundry.errors.'.$this->errorCode;
        $detail = __($key, $this->params);

        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => $this->errorCode,
            'status' => $this->status,
            'detail' => $detail === $key ? $this->errorCode : $detail,
            'instance' => $request->getRequestUri(),
            'code' => $this->errorCode,
            'params' => $this->params,
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
