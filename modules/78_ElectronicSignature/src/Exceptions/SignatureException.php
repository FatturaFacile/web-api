<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the electronic signature module was refused. The `code` is a translation key suffix: the frontend
 * dictionary holds the text as `signatures.error.<code>`, so the API carries no user-facing copy.
 * Rendered as an RFC 7807 problem.
 */
class SignatureException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  values the translated text may interpolate
     */
    public function __construct(private readonly string $errorCode, private readonly int $status = 422, private readonly array $context = [])
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
            'detail' => 'signatures.error.'.$this->errorCode,
            'instance' => $request->getRequestUri(),
            'code' => $this->errorCode,
            'message' => 'signatures.error.'.$this->errorCode,
            'context' => $this->context,
        ], $this->status, ['Content-Type' => 'application/problem+json']);
    }
}
