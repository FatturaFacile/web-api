<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Exceptions;

use Illuminate\Http\JsonResponse;
use Modules\Manufacturing\Support\MesProblem;
use RuntimeException;

/**
 * A request of the MES Core that cannot be served as it is (a malformed or no longer available cursor). Rendered as an
 * RFC 9457 problem; a pruned cursor is 410 Gone, which tells the MES Core to bootstrap the resource again.
 */
final class MesSyncException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status, public readonly string $problemCode, public readonly string $title)
    {
        parent::__construct($message);
    }

    /**
     * The cursor is not one this API issued.
     */
    public static function invalidCursor(): self
    {
        return new self('The cursor is malformed.', 400, 'sync.invalid_cursor', 'Invalid cursor');
    }

    /**
     * The cursor points before the pruned part of the change log, or beyond its end.
     */
    public static function cursorGone(): self
    {
        return new self('The cursor is no longer available: bootstrap the resource again.', 410, 'sync.cursor_gone', 'Cursor gone');
    }

    /**
     * Problem response of the exception.
     */
    public function render(): JsonResponse
    {
        return MesProblem::response($this->status, $this->problemCode, $this->title, $this->getMessage());
    }
}
