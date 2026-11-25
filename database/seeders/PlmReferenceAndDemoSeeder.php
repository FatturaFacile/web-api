<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Modules\Manufacturing\Models\MfgBom;
use Modules\Manufacturing\Models\MfgItem;
use Modules\Plm\Enums\ApprovalDepartment;
use Modules\Plm\Enums\CadSourceSystem;
use Modules\Plm\Enums\ChangeReason;
use Modules\Plm\Enums\EcoWorkflowStage;
use Modules\Plm\Enums\RevisionLifecycleState;
use Modules\Plm\Enums\StockDispositionRule;
use Modules\Plm\Models\PlmCadImportSession;
use Modules\Plm\Models\PlmChangeOrder;
use Modules\Plm\Models\PlmItemRevision;
use Modules\Plm\Models\PlmTechnicalDossier;
use Modules\Plm\Strategies\CeUkcaMachineryRoHsStrategy;

/**
 * Idempotent reference and demo data for PLM & Engineering Change (Mod 168):
 * revisions, CAD-to-mBOM import session, CE/UKCA/OMacch technical dossier, and ECR/ECO/ECN.
 */
final class PlmReferenceAndDemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $item = MfgItem::query()->where('tenant_id', $tenant->id)->first();
            if (! $item instanceof MfgItem) {
                continue;
            }

            $bom = MfgBom::query()->where('tenant_id', $tenant->id)->where('mfg_item_id', $item->id)->first();

            $revA = PlmItemRevision::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'mfg_item_id' => $item->id,
                    'revision' => 'A',
                ],
                [
                    'engineering_part_number' => $item->item_code,
                    'description' => $item->name.' — Initial Release',
                    'status' => PlmItemRevision::Released,
                    'lifecycle_state' => RevisionLifecycleState::ReleasedForProduction->value,
                    'mbom_id' => $bom?->id,
                    'rohs_and_reach_compliant' => true,
                    'conflict_minerals_compliant' => true,
                    'technical_file_ref' => 'TF-CE-OMACCH-'.$item->item_code.'-A',
                    'cad_drawing_pdf_and_step_file_ids' => [
                        ['id' => 'cad-step-1', 'filename' => $item->item_code.'_RevA.step', 'format' => 'STEP', 'version' => 'A'],
                        ['id' => 'cad-pdf-1', 'filename' => $item->item_code.'_RevA.pdf', 'format' => 'PDF', 'version' => 'A'],
                    ],
                    'effective_from' => now()->toDateString(),
                    'released_at' => now(),
                ]
            );

            PlmTechnicalDossier::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'dossier_code' => 'DOSSIER-'.$item->item_code.'-A',
                ],
                [
                    'mfg_item_id' => $item->id,
                    'revision_id' => $revA->id,
                    'strategy_code' => CeUkcaMachineryRoHsStrategy::CODE,
                    'machinery_directive_ref' => '2006/42/EC + EU 2023/1230 + CH OMacch (RS 819.14)',
                    'ce_declaration_status' => 'compliant',
                    'ukca_declaration_status' => 'compliant',
                    'omacch_mra_ch_eu_compliant' => true,
                    'rohs_compliant' => true,
                    'reach_svhc_compliant' => true,
                    'conflict_minerals_compliant' => true,
                    'iso_audit_standards_json' => ['ISO_9001', 'IATF_16949', 'ISO_13485'],
                    'last_evaluated_at' => now(),
                ]
            );

            PlmCadImportSession::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'session_code' => 'CAD-DEMO-'.$item->item_code,
                ],
                [
                    'product_id' => $item->id,
                    'target_revision_id' => $revA->id,
                    'target_mbom_id' => $bom?->id,
                    'cad_source_system' => CadSourceSystem::SolidWorks->value,
                    'source_file_metadata' => ['filename' => $item->item_code.'_eBOM.xml', 'format' => 'xml'],
                    'parsed_part_list_json' => [
                        ['item_code' => $item->item_code, 'name' => $item->name, 'quantity' => '1.0000', 'uom' => $item->uom, 'unit_cost' => '25.0000', 'level' => 1],
                    ],
                    'new_parts_to_create_count' => 0,
                    'status' => 'Reviewed',
                ]
            );

            $matrix = [];
            foreach (ApprovalDepartment::cases() as $dept) {
                $matrix[$dept->value] = ['department' => $dept->value, 'status' => 'pending', 'actor_id' => null, 'decided_at' => null, 'sign_off_hash' => null];
            }

            PlmChangeOrder::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'code' => 'ECO-DEMO-001',
                ],
                [
                    'title' => 'Bearing upgrade & RoHS verification',
                    'reason' => 'Supplier component obsolescence and efficiency improvement',
                    'change_type' => 'material',
                    'priority' => 'high',
                    'status' => PlmChangeOrder::InReview,
                    'workflow_stage' => EcoWorkflowStage::UnderEvaluation->value,
                    'change_reason' => ChangeReason::ComponentObsolescence->value,
                    'is_safety_immediate_cutover' => false,
                    'existing_stock_disposition_rule' => StockDispositionRule::UseUpEsaurimentoScorte->value,
                    'approvals_matrix_json' => $matrix,
                ]
            );
        }
    }
}
