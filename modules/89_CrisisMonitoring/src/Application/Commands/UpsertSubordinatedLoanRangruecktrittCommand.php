<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Commands;

/**
 * Creates (no id) or updates a subordinated loan of the Rangrücktritt registry.
 */
final readonly class UpsertSubordinatedLoanRangruecktrittCommand
{
    /**
     * @param  array<string, mixed>  $attributes  the validated attributes of the loan
     */
    public function __construct(
        public ?string $id,
        public array $attributes,
    ) {}
}
