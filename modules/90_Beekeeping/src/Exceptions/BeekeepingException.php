<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the beekeeping module was refused. The `code` is a translation key (lang beekeeping.errors.<code>, and
 * beekeeping.error.<code> in the frontend dictionary). Rendered as an RFC 7807 problem.
 */
final class BeekeepingException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context  extra data the screens show (for instance the withdrawal end date)
     */
    public function __construct(string $message, private readonly string $errorCode = 'refused', private readonly int $status = 422, private readonly array $context = [])
    {
        parent::__construct($message);
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
        $key = 'beekeeping.errors.'.$this->errorCode;
        $detail = __($key, $this->context) === $key ? $this->getMessage() : __($key, $this->context);

        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => $this->errorCode,
            'status' => $this->status,
            'detail' => $detail,
            'instance' => $request->getRequestUri(),
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'context' => $this->context,
        ], $this->status);
    }
}
