<?php

declare(strict_types=1);

namespace Modules\Plm\CQRS\Handlers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Manufacturing\Support\Money;
use Modules\Plm\CQRS\Commands\ImportCadBomAndCompareWithMbomCommand;
use Modules\Plm\Enums\CadImportSessionStatus;
use Modules\Plm\Enums\RevisionLifecycleState;
use Modules\Plm\Models\PlmCadImportSession;
use Modules\Plm\Models\PlmItemRevision;
use Modules\Plm\Services\CadPayloadParser;
use Modules\Plm\Services\PlmBomDiffService;

final readonly class ImportCadBomAndCompareWithMbomHandler
{
    public function __construct(
        private CadPayloadParser $parser,
        private PlmBomDiffService $diffs,
        private BillOfMaterialsServiceInterface $boms,
    ) {}

    public function handle(ImportCadBomAndCompareWithMbomCommand $command): PlmCadImportSession
    {
        $parts = $this->parser->parse($command->rawPayload, $command->payloadFormat, $command->parts);

        return DB::transaction(function () use ($command, $parts): PlmCadImportSession {
            $product = MfgItem::query()
                ->where('tenant_id', $command->tenantId)
                ->where('item_code', $command->productCode)
                ->first();

            $createdParts = [];
            if (! $product instanceof MfgItem && $command->autoCreateMissingParts) {
                $product = MfgItem::create([
                    'tenant_id' => $command->tenantId,
                    'item_code' => $command->productCode,
                    'name' => $command->bomName ?? $command->productCode,
                    'item_type' => 'finished_good',
                    'procurement_type' => 'make',
                    'uom' => 'PCS',
                    'standard_cost' => '0.0000',
                    'current_cost' => '0.0000',
                    'is_active' => true,
                ]);
                $createdParts[] = ['mfg_item_id' => (string) $product->id, 'item_code' => $product->item_code, 'is_product' => true];
            }

            $warnings = [];
            $mbomComponents = [];
            foreach ($parts as $part) {
                $item = MfgItem::query()
                    ->where('tenant_id', $command->tenantId)
                    ->where('item_code', $part['item_code'])
                    ->first();

                if (! $item instanceof MfgItem && $command->autoCreateMissingParts) {
                    $item = MfgItem::create([
                        'tenant_id' => $command->tenantId,
                        'item_code' => $part['item_code'],
                        'name' => $part['name'],
                        'item_type' => 'raw_material',
                        'procurement_type' => 'buy',
                        'uom' => $part['uom'],
                        'standard_cost' => Money::round($part['unit_cost'], 4),
                        'current_cost' => Money::round($part['unit_cost'], 4),
                        'is_active' => true,
                    ]);
                    $createdParts[] = [
                        'mfg_item_id' => (string) $item->id,
                        'item_code' => $item->item_code,
                        'drawing_number' => $part['drawing_number'],
                        'is_product' => false,
                    ];
                } elseif (! $item instanceof MfgItem) {
                    $warnings[] = "Missing item [{$part['item_code']}] skipped because auto_create_missing_parts is disabled.";

                    continue;
                }

                if ($product instanceof MfgItem && (string) $item->id !== (string) $product->id && $part['level'] === 1) {
                    $mbomComponents[] = [
                        'component_item_id' => (string) $item->id,
                        'quantity_per' => $part['quantity'],
                        'notes' => $part['drawing_number'] !== null ? "CAD DWG: {$part['drawing_number']}" : null,
                    ];
                }
            }

            $existingMbom = $product instanceof MfgItem
                ? MfgBom::query()
                    ->where('tenant_id', $command->tenantId)
                    ->where('mfg_item_id', $product->id)
                    ->whereIn('status', MfgBom::LiveStatuses)
                    ->orderByDesc('version')
                    ->first()
                : null;

            $diff = $this->diffs->compareEbomWithMbom($parts, $existingMbom, $command->tenantId);

            $targetRevision = null;
            if ($product instanceof MfgItem) {
                $targetRevision = PlmItemRevision::query()
                    ->where('tenant_id', $command->tenantId)
                    ->where('mfg_item_id', $product->id)
                    ->where('revision', $command->revisionCode)
                    ->first();

                if (! $targetRevision instanceof PlmItemRevision) {
                    $targetRevision = PlmItemRevision::create([
                        'tenant_id' => $command->tenantId,
                        'mfg_item_id' => $product->id,
                        'revision' => $command->revisionCode,
                        'engineering_part_number' => $command->productCode,
                        'description' => $command->bomName ?? $product->name,
                        'status' => PlmItemRevision::Draft,
                        'lifecycle_state' => RevisionLifecycleState::InDesignPrototype->value,
                        'ebom_structure_json' => ['source' => $command->cadSourceSystem, 'parts' => $parts],
                        'mbom_id' => $existingMbom?->id,
                        'cad_drawing_pdf_and_step_file_ids' => $command->cadAttachments,
                        'rohs_and_reach_compliant' => true,
                        'conflict_minerals_compliant' => true,
                    ]);
                } else {
                    $targetRevision->update([
                        'ebom_structure_json' => ['source' => $command->cadSourceSystem, 'parts' => $parts],
                        'cad_drawing_pdf_and_step_file_ids' => $command->cadAttachments !== [] ? $command->cadAttachments : $targetRevision->cad_drawing_pdf_and_step_file_ids,
                    ]);
                }
            }

            $status = CadImportSessionStatus::Parsed;
            $committedAt = null;
            $targetMbomId = $existingMbom?->id;

            if ($command->commitToMbom && $product instanceof MfgItem && $mbomComponents !== []) {
                if ($existingMbom instanceof MfgBom && $existingMbom->isDraft()) {
                    $updatedBom = $this->boms->updateDraft($existingMbom, ['components' => $mbomComponents], $command->userId);
                    $targetMbomId = (string) $updatedBom->id;
                } elseif (! $existingMbom instanceof MfgBom) {
                    $createdBom = $this->boms->createBom(new CreateBomDTO(
                        tenantId: $command->tenantId,
                        mfgItemId: (string) $product->id,
                        name: $command->bomName ?? $product->name,
                        components: $mbomComponents,
                        revision: $command->revisionCode,
                    ), $command->userId);
                    $targetMbomId = (string) $createdBom->id;
                } else {
                    $revisedBom = $this->boms->reviseBom($existingMbom, $command->revisionCode, "CAD Import ({$command->cadSourceSystem})", [], null, $command->userId);
                    $targetMbomId = (string) $revisedBom->id;
                }

                $targetRevision->update(['mbom_id' => $targetMbomId]);
                $status = CadImportSessionStatus::Committed;
                $committedAt = now();
            }

            return PlmCadImportSession::create([
                'tenant_id' => $command->tenantId,
                'session_code' => 'CAD-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)),
                'product_id' => $product?->id,
                'target_revision_id' => $targetRevision?->id,
                'target_mbom_id' => $targetMbomId,
                'cad_source_system' => $command->cadSourceSystem,
                'source_file_metadata' => $command->sourceFileMetadata,
                'parsed_part_list_json' => $parts,
                'new_parts_to_create_count' => count($createdParts),
                'created_parts_json' => $createdParts,
                'changed_quantities_diff_list' => $diff['modified'],
                'removed_parts_list' => $diff['removed'],
                'side_by_side_diff_json' => $diff,
                'cost_rollup_comparison_json' => $diff['cost_rollup'],
                'validation_warnings_json' => $warnings,
                'status' => $status->value,
                'created_by' => $command->userId,
                'committed_at' => $committedAt,
            ])->load(['product:id,item_code,name', 'targetRevision', 'targetMbom']);
        });
    }
}
