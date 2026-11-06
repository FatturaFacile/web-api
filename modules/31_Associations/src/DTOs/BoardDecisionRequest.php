<?php

declare(strict_types=1);

namespace Modules\Associations\DTOs;

/**
 * Where a request to the board ended up: a reference of the governance module, such as the meeting it was queued for.
 */
final readonly class BoardDecisionRequest
{
    public function __construct(public ?string $reference = null) {}
}
