<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Application\Commands;

final readonly class RebuildTenantVectorIndexCommand
{
    public function __construct(
        public string $tenantId,
        public ?string $userId = null,
        public bool $runNow = false,
    ) {}
}
