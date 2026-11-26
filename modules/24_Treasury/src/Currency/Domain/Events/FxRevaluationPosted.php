<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Events;

final readonly class FxRevaluationPosted
{
    public function __construct(
        public string $tenantId,
        public string $revaluationId,
        public string $periodEnd,
        public string $netResult,
        public ?string $journalEntryId,
    ) {}
}
