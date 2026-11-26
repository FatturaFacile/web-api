<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Commands;

/**
 * Posts a draft revaluation to the ledger, once: the same Idempotency-Key replays the result, another key on a posted
 * revaluation is a conflict.
 */
final readonly class PostFxRevaluationCommand
{
    public function __construct(
        public string $tenantId,
        public string $revaluationId,
        public string $idempotencyKey,
        public ?string $actorId = null,
    ) {}
}
