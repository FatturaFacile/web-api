<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Application\Commands;

use Modules\VanSalesAndFieldForce\Models\VanLoadManifest;
use Modules\VanSalesAndFieldForce\Services\VanLoadManifestService;

final readonly class IssueMorningLoadManifestHandler
{
    public function __construct(private VanLoadManifestService $service) {}

    public function handle(IssueMorningLoadManifestCommand $command): VanLoadManifest
    {
        $manifest = VanLoadManifest::query()
            ->where('tenant_id', $command->tenantId)
            ->findOrFail($command->manifestId);

        return $this->service->issueGlobalLoadDdt($command->tenantId, $manifest);
    }
}
