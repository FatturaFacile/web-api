<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Commands;

use App\Services\Privacy\Governance\RopaDefaultTemplateService;

final readonly class SeedDefaultRopaTemplatesHandler
{
    public function __construct(private RopaDefaultTemplateService $templates) {}

    /**
     * @return array{ropa_created: int, ropa_existing: int, rules_created: int, rules_existing: int}
     */
    public function handle(SeedDefaultRopaTemplatesCommand $command): array
    {
        return $this->templates->seed($command->tenantId);
    }
}
