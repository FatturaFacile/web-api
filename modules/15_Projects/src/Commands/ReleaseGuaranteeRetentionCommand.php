<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Releases the guarantee retention of a project, in full when no amount is given (Mod 081).
 */
final readonly class ReleaseGuaranteeRetentionCommand
{
    public function __construct(
        public string $projectId,
        public ?string $amount,
        public string $releaseDate,
        public bool $acceptanceConfirmed,
        public ?string $milestoneId,
        public ?string $reason,
        public string $userId,
    ) {}
}
