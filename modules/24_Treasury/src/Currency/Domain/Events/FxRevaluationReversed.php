<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Events;

final readonly class FxRevaluationReversed
{
    public function __construct(
        public string $tenantId,
        public string $revaluationId,
        public string $reversalDate,
        public ?string $reversalJournalEntryId,
    ) {}
}
