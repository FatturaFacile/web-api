<?php

declare(strict_types=1);

namespace Modules\Projects\Queries;

/**
 * The guarantee retention of a project: withheld, released, still held and the release rule (Mod 081).
 */
final readonly class GetProjectRetentionBalanceQuery
{
    public function __construct(
        public string $projectId,
    ) {}
}
