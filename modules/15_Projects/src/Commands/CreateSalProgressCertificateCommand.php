<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Drafts the next SAL progress certificate of a project from its cumulative completion percentage (Mod 081).
 */
final readonly class CreateSalProgressCertificateCommand
{
    public function __construct(
        public string $projectId,
        public string $periodStartDate,
        public string $periodEndDate,
        public string $cumulativeCompletionPercent,
        public ?string $notes,
        public string $userId,
    ) {}
}
