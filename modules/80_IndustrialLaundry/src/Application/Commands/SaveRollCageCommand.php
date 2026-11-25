<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

final readonly class SaveRollCageCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $tenantId,
        public ?string $userId,
        public array $data,
        public ?string $rollCageId = null,
    ) {}
}
