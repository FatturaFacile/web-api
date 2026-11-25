<?php

declare(strict_types=1);

namespace Modules\Intercompany\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * An intercompany operation cannot be done as asked (a missing party link, a document outside the group): the client
 * corrects the input and asks again. `$code` is a translation key suffix under `intercompany.error.`.
 */
class IntercompanyException extends InvalidArgumentException
{
    public function __construct(string $message, public readonly string $errorCode = 'invalid')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $this->getMessage(),
            'errors' => ['intercompany' => [$this->getMessage()]],
            'error_code' => 'intercompany.error.'.$this->errorCode,
        ], 422);
    }
}
