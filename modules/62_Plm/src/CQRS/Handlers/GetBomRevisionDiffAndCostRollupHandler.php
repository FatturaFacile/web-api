<?php

declare(strict_types=1);

namespace Modules\Plm\CQRS\Handlers;

use Illuminate\Validation\ValidationException;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Plm\CQRS\Queries\GetBomRevisionDiffAndCostRollupQuery;
use Modules\Plm\Models\PlmItemRevision;
use Modules\Plm\Services\PlmBomDiffService;

final readonly class GetBomRevisionDiffAndCostRollupHandler
{
    public function __construct(private PlmBomDiffService $diffs) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(GetBomRevisionDiffAndCostRollupQuery $query): array
    {
        if ($query->revisionIdForEbomVsMbom !== null) {
            $revision = PlmItemRevision::query()
                ->where('tenant_id', $query->tenantId)
                ->findOrFail($query->revisionIdForEbomVsMbom);

            $mbom = $revision->mbom_id !== null
                ? MfgBom::query()->where('tenant_id', $query->tenantId)->find($revision->mbom_id)
                : null;

            $parts = is_array($revision->ebom_structure_json['parts'] ?? null)
                ? $revision->ebom_structure_json['parts']
                : [];

            return $this->diffs->compareEbomWithMbom($parts, $mbom, $query->tenantId);
        }

        $fromBomId = $query->fromBomId;
        $toBomId = $query->toBomId;

        if ($query->fromRevisionId !== null && $query->toRevisionId !== null) {
            $fromRev = PlmItemRevision::query()->where('tenant_id', $query->tenantId)->findOrFail($query->fromRevisionId);
            $toRev = PlmItemRevision::query()->where('tenant_id', $query->tenantId)->findOrFail($query->toRevisionId);
            $fromBomId = $fromRev->mbom_id;
            $toBomId = $toRev->mbom_id;
        }

        if ($fromBomId === null || $toBomId === null) {
            throw ValidationException::withMessages(['bom_diff' => [__('plm_errors.diff.boms_required')]]);
        }

        $fromBom = MfgBom::query()->where('tenant_id', $query->tenantId)->findOrFail($fromBomId);
        $toBom = MfgBom::query()->where('tenant_id', $query->tenantId)->findOrFail($toBomId);

        return $this->diffs->compareBoms($fromBom, $toBom);
    }
}
