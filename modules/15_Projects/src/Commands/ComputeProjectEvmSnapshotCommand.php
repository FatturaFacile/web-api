<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Computes and stores the financial snapshot of a project and of its WBS nodes, rolls up the cost ledger, evaluates the
 * Mod 008 alert thresholds and emits the domain event.
 */
final readonly class ComputeProjectEvmSnapshotCommand
{
    public function __construct(
        public string $projectId,
        public string $trigger = 'manual',
        public ?string $userId = null,
        public ?string $asOf = null,
    ) {}
}
