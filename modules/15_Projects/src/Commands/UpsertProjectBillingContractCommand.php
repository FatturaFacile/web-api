<?php

declare(strict_types=1);

namespace Modules\Projects\Commands;

/**
 * Creates or updates the billing contract of a project; updates carry the version read (Mod 081).
 */
final readonly class UpsertProjectBillingContractCommand
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $projectId,
        public array $attributes,
        public ?int $expectedVersion,
        public string $userId,
    ) {}
}
