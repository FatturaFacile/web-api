<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

final readonly class IssueMorningLoadManifestCommand
{
    public function __construct(
        public string $tenantId,
        public string $manifestId,
    ) {}
}
