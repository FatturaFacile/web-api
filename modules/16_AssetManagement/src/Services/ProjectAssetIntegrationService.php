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
use Modules\AssetManagement\Support\Money;

/**
 * Allocation of assets to projects with utilization rates and attributed cost (decimal strings).
 */
final readonly class ProjectAssetIntegrationService implements ProjectAssetIntegrationServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcher $dispatcher
    ) {}

    public function allocateAssetToProject(AllocateProjectAssetDTO $dto, ?string $actorUserId = null): ProjectAssetAllocation
    {
        return DB::transaction(function () use ($dto, $actorUserId) {
            $asset = FixedAsset::where('tenant_id', $dto->tenantId)->where('id', $dto->assetId)->lockForUpdate()->firstOrFail();
            $actorUuid = ! empty($actorUserId) && Str::isUuid($actorUserId) ? $actorUserId : null;

            $attributedCost = Money::round(Money::add(Money::mul($dto->utilizedHours, $dto->hourlyRate), Money::mul($dto->utilizedDays, $dto->dailyRate)));

            $allocation = ProjectAssetAllocation::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $dto->tenantId,
                'asset_id' => $asset->id,
                'project_id' => $dto->projectId,
                'allocated_from' => $dto->allocatedFrom,
                'allocated_to' => $dto->allocatedTo,
                'hourly_rate' => Money::round($dto->hourlyRate),
                'daily_rate' => Money::round($dto->dailyRate),
                'utilized_hours' => Money::round($dto->utilizedHours),
                'utilized_days' => Money::round($dto->utilizedDays),
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
                hourlyRate: Money::toFloat($dto->hourlyRate),
                dailyRate: Money::toFloat($dto->dailyRate),
                allocatedFrom: $dto->allocatedFrom,
                tenantId: $dto->tenantId,
                companyId: (string) ($asset->company_id ?? $dto->tenantId)
            );
            $this->dispatcher->publishAndDispatch($assignedEvent);

            return $allocation;
        });
    }

    public function recordUtilization(
        ProjectAssetAllocation $allocation,
        string|int|float $additionalHours,
        string|int|float $additionalDays = 0,
        ?string $actorUserId = null
    ): ProjectAssetAllocation {
        return DB::transaction(function () use ($allocation, $additionalHours, $additionalDays) {
            $locked = ProjectAssetAllocation::where('id', $allocation->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();

            $newHours = Money::round(Money::add($locked->utilized_hours, $additionalHours));
            $newDays = Money::round(Money::add($locked->utilized_days, $additionalDays));
            $newCost = Money::round(Money::add(Money::mul($newHours, $locked->hourly_rate), Money::mul($newDays, $locked->daily_rate)));

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
                    totalAttributedCost: Money::toFloat($allocation->attributed_cost),
                    releasedAt: $releasedAt->toIso8601String(),
                    tenantId: (string) $allocation->tenant_id,
                    companyId: (string) $allocation->tenant_id
                );
                $this->dispatcher->publishAndDispatch($releasedEvent);

                $count++;
            }

            return $count;
        });
    }
}
