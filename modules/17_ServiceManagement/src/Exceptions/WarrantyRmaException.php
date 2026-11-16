<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Domain error of the warranty / RMA slice, rendered as `{"message","code","errors"?}` with its HTTP status.
 */
final class WarrantyRmaException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self(
            sprintf('Transition from "%s" to "%s" is not allowed.', $from, $to),
            'invalid_transition',
            409,
        );
    }

    public static function alreadyDecided(): self
    {
        return new self('The estimate has already been decided.', 'already_decided', 409);
    }

    public static function notFound(string $code = 'not_found', string $message = 'Resource not found.'): self
    {
        return new self($message, $code, 404);
    }

    public static function validation(string $field, string $message, string $code = 'validation_error'): self
    {
        return new self($message, $code, 422, [$field => [$message]]);
    }

    /**
     * Client errors (4xx) are expected outcomes: skip default error logging.
     */
    public function report(): bool
    {
        return true;
    }

    public function render(): JsonResponse
    {
        $body = ['message' => $this->getMessage(), 'code' => $this->errorCode];

        if ($this->errors !== []) {
            $body['errors'] = $this->errors;
        }

        return response()->json(array_merge($body, $this->extra), $this->status);
    }
}
