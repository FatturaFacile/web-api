<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A rule of the currency domain refused an operation. The HTTP status and the machine code tell the API what to answer: the
 * exception renders itself as `{success: false, message, code, errors}` wherever it is thrown (a controller or a document
 * emission that needs a rate), and is not reported as a server error.
 */
class CurrencyDomainException extends RuntimeException
{
    public function __construct(string $message, private readonly string $errorCode = 'currency_error', private readonly int $status = 422, private readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
            'errors' => $this->field === null ? (object) [] : [$this->field => [$this->getMessage()]],
        ], $this->status);
    }

    public function report(): bool
    {
        return false;
    }
}
