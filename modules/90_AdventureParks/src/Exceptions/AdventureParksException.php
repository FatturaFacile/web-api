<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A business rule of the adventure parks module was refused. The `code` is a translation key (lang adventure_parks.errors.<code>)
 * and a stable identifier for the clients (ppe_blocked, waiver_missing, slot_sold_out, ...). Rendered as an RFC 7807 problem with
 * the machine readable `context` of the refusal.
 */
final class AdventureParksException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(string $message, private readonly string $errorCode = 'refused', private readonly array $context = [], private readonly int $status = 422)
    {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function refuse(string $code, string $message, array $context = [], int $status = 422): self
    {
        return new self($message, $code, $context, $status);
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
        $key = 'adventure_parks.errors.'.$this->errorCode;
        $detail = __($key, $this->context);
        $detail = $detail === $key ? $this->getMessage() : $detail;

        return response()->json([
            'type' => 'https://httpstatuses.com/'.$this->status,
            'title' => $this->errorCode,
            'status' => $this->status,
            'detail' => $detail,
            'instance' => $request->getRequestUri(),
            'code' => $this->errorCode,
            'message' => $detail,
            'errors' => [$this->errorCode => [$detail]],
            'context' => (object) $this->context,
        ], $this->status);
    }
}
