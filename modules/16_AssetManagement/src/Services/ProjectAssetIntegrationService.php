<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AssetManagement\Contracts\ProjectAssetIntegrationServiceInterface;
use Modules\AssetManagement\DTOs\AllocateProjectAssetDTO;
use Modules\AssetManagement\Events\ProjectAssetAssignedEvent;
use Modules\AssetManagement\Events\ProjectAssetReleasedEvent;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Models\ProjectAssetAllocation;

final readonly class ProjectAssetIntegrationService implements ProjectAssetIntegrationServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcher $dispatcher
    ) {}

    public function allocateAssetToProject(AllocateProjectAssetDTO $dto, ?string $actorUserId = null): ProjectAssetAllocation
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $asset = FixedAsset::where('id', $dto->assetId)->lockForUpdate()->firstOrFail();
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $attributedCost = round(
                ($dto->utilizedHours * $dto->hourlyRate) + ($dto->utilizedDays * $dto->dailyRate),
                2
            );

            $allocation = ProjectAssetAllocation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'asset_id' => $asset->id,
                'project_id' => $dto->projectId,
                'allocated_from' => $dto->allocatedFrom,
                'allocated_to' => $dto->allocatedTo,
                'hourly_rate' => $dto->hourlyRate,
                'daily_rate' => $dto->dailyRate,
                'utilized_hours' => $dto->utilizedHours,
                'utilized_days' => $dto->utilizedDays,
                'attributed_cost' => $attributedCost,
                'status' => 'active',
                'allocated_by_user_id' => $actorUuid,
                'notes' => $dto->notes,
            ]);

            $asset->update(['project_id' => $dto->projectId]);

            $this->auditLedger->record(
                action: 'PROJECT_ASSET_ALLOCATED',
                model: $allocation,
                payloadBefore: null,
                payloadAfter: $allocation->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            $assignedEvent = new ProjectAssetAssignedEvent(
                allocationId: (string) $allocation->id,
                assetId: (string) $asset->id,
                projectId: $dto->projectId,
                hourlyRate: $dto->hourlyRate,
                dailyRate: $dto->dailyRate,
                allocatedFrom: $dto->allocatedFrom,
                tenantId: $dto->tenantId,
                companyId: (string) ($asset->company_id ?? $dto->tenantId)
            );
            $this->dispatcher->publish($assignedEvent);
            event($assignedEvent);

            return $allocation;
        });
    }

    public function recordUtilization(
        ProjectAssetAllocation $allocation,
        float $additionalHours,
        float $additionalDays = 0.0,
        ?string $actorUserId = null
    ): ProjectAssetAllocation {
        return DB::transaction(function () use ($allocation, $additionalHours, $additionalDays) {
            $locked = ProjectAssetAllocation::where('id', $allocation->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();

            $newHours = round((float) $locked->utilized_hours + $additionalHours, 2);
            $newDays = round((float) $locked->utilized_days + $additionalDays, 2);
            $newCost = round(
                ($newHours * (float) $locked->hourly_rate) + ($newDays * (float) $locked->daily_rate),
                2
            );

            $locked->update([
                'utilized_hours' => $newHours,
                'utilized_days' => $newDays,
                'attributed_cost' => $newCost,
            ]);

            $this->auditLedger->record(
                action: 'PROJECT_ASSET_UTILIZATION_RECORDED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->fresh()->toArray(),
                moduleCode: 'MODULE_16_EAM'
            );

            return $locked->fresh();
        });
    }

    public function releaseProjectAssets(string $projectId, ?string $actorUserId = null): int
    {
        return DB::transaction(function () use ($projectId) {
            $allocations = ProjectAssetAllocation::where('project_id', $projectId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->get();

            $count = 0;
            foreach ($allocations as $allocation) {
                $releasedAt = now();
                $allocation->update([
                    'status' => 'released',
                    'released_at' => $releasedAt,
                ]);

                FixedAsset::where('id', $allocation->asset_id)
                    ->where('project_id', $projectId)
                    ->update(['project_id' => null]);

                $releasedEvent = new ProjectAssetReleasedEvent(
                    allocationId: (string) $allocation->id,
                    assetId: (string) $allocation->asset_id,
                    projectId: $projectId,
                    totalAttributedCost: (float) $allocation->attributed_cost,
                    releasedAt: $releasedAt->toIso8601String(),
                    tenantId: (string) $allocation->tenant_id,
                    companyId: (string) $allocation->tenant_id
                );
                $this->dispatcher->publish($releasedEvent);
                event($releasedEvent);

                $count++;
            }

            return $count;
        });
    }
}
