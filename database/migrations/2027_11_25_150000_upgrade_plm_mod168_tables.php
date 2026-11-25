<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Upgrade PLM (Mod 168) for full ECR/ECO/ECN workflows, CAD-to-eBOM/mBOM import sessions,
     * multi-level revision lineage, departmental approval matrix, stock disposition cutover,
     * MES drawing obsolescence watermarks, and CE/UKCA/OMacch technical construction dossiers.
     */
    public function up(): void
    {
        if (Schema::hasTable('plm_item_revisions')) {
            Schema::table('plm_item_revisions', function (Blueprint $table): void {
                if (! Schema::hasColumn('plm_item_revisions', 'parent_revision_id')) {
                    $table->uuid('parent_revision_id')->nullable()->index();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'engineering_part_number')) {
                    $table->string('engineering_part_number', 80)->nullable()->index();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'description')) {
                    $table->text('description')->nullable();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'lifecycle_state')) {
                    $table->string('lifecycle_state', 32)->default('InDesign_Prototype')->index();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'ebom_structure_json')) {
                    $table->json('ebom_structure_json')->nullable();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'mbom_id')) {
                    $table->uuid('mbom_id')->nullable()->index();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'routing_id')) {
                    $table->uuid('routing_id')->nullable()->index();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'cad_drawing_pdf_and_step_file_ids')) {
                    $table->json('cad_drawing_pdf_and_step_file_ids')->nullable();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'rohs_and_reach_compliant')) {
                    $table->boolean('rohs_and_reach_compliant')->default(false);
                }
                if (! Schema::hasColumn('plm_item_revisions', 'conflict_minerals_compliant')) {
                    $table->boolean('conflict_minerals_compliant')->default(false);
                }
                if (! Schema::hasColumn('plm_item_revisions', 'technical_file_ref')) {
                    $table->string('technical_file_ref', 120)->nullable();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'regulatory_metadata_json')) {
                    $table->json('regulatory_metadata_json')->nullable();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'mes_drawing_obsolete_watermark')) {
                    $table->boolean('mes_drawing_obsolete_watermark')->default(false);
                }
                if (! Schema::hasColumn('plm_item_revisions', 'superseded_by_revision_id')) {
                    $table->uuid('superseded_by_revision_id')->nullable();
                }
                if (! Schema::hasColumn('plm_item_revisions', 'audit_trail_json')) {
                    $table->json('audit_trail_json')->nullable();
                }
            });
        }

        if (Schema::hasTable('plm_change_orders')) {
            Schema::table('plm_change_orders', function (Blueprint $table): void {
                if (! Schema::hasColumn('plm_change_orders', 'workflow_stage')) {
                    $table->string('workflow_stage', 32)->default('Draft')->index();
                }
                if (! Schema::hasColumn('plm_change_orders', 'change_reason')) {
                    $table->string('change_reason', 40)->default('CostReduction')->index();
                }
                if (! Schema::hasColumn('plm_change_orders', 'is_safety_immediate_cutover')) {
                    $table->boolean('is_safety_immediate_cutover')->default(false);
                }
                if (! Schema::hasColumn('plm_change_orders', 'existing_stock_disposition_rule')) {
                    $table->string('existing_stock_disposition_rule', 40)->default('UseUp_EsaurimentoScorte');
                }
                if (! Schema::hasColumn('plm_change_orders', 'effective_date_or_lot_cutover')) {
                    $table->string('effective_date_or_lot_cutover', 120)->nullable();
                }
                if (! Schema::hasColumn('plm_change_orders', 'lot_serial_cutover_code')) {
                    $table->string('lot_serial_cutover_code', 80)->nullable();
                }
                if (! Schema::hasColumn('plm_change_orders', 'approvals_matrix_json')) {
                    $table->json('approvals_matrix_json')->nullable();
                }
                if (! Schema::hasColumn('plm_change_orders', 'ecn_number')) {
                    $table->string('ecn_number', 40)->nullable();
                }
                if (! Schema::hasColumn('plm_change_orders', 'ecn_released_at')) {
                    $table->dateTime('ecn_released_at')->nullable();
                }
                if (! Schema::hasColumn('plm_change_orders', 'cutover_summary_json')) {
                    $table->json('cutover_summary_json')->nullable();
                }
            });
        }

        if (Schema::hasTable('plm_affected_items')) {
            Schema::table('plm_affected_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('plm_affected_items', 'from_revision_id')) {
                    $table->uuid('from_revision_id')->nullable();
                }
                if (! Schema::hasColumn('plm_affected_items', 'target_revision_id')) {
                    $table->uuid('target_revision_id')->nullable();
                }
                if (! Schema::hasColumn('plm_affected_items', 'disposition_rule')) {
                    $table->string('disposition_rule', 40)->nullable();
                }
                if (! Schema::hasColumn('plm_affected_items', 'cutover_mode')) {
                    $table->string('cutover_mode', 40)->default('PhaseOut_UseUpStock');
                }
                if (! Schema::hasColumn('plm_affected_items', 'effective_date')) {
                    $table->date('effective_date')->nullable();
                }
                if (! Schema::hasColumn('plm_affected_items', 'lot_serial_cutover')) {
                    $table->string('lot_serial_cutover', 80)->nullable();
                }
                if (! Schema::hasColumn('plm_affected_items', 'quarantined_lots_count')) {
                    $table->unsignedInteger('quarantined_lots_count')->default(0);
                }
                if (! Schema::hasColumn('plm_affected_items', 'mes_drawings_obsoleted_count')) {
                    $table->unsignedInteger('mes_drawings_obsoleted_count')->default(0);
                }
            });
        }

        if (Schema::hasTable('plm_change_approvals')) {
            Schema::table('plm_change_approvals', function (Blueprint $table): void {
                if (! Schema::hasColumn('plm_change_approvals', 'department')) {
                    $table->string('department', 32)->nullable()->index();
                }
                if (! Schema::hasColumn('plm_change_approvals', 'actor_role')) {
                    $table->string('actor_role', 64)->nullable();
                }
                if (! Schema::hasColumn('plm_change_approvals', 'sign_off_hash')) {
                    $table->string('sign_off_hash', 128)->nullable();
                }
                if (! Schema::hasColumn('plm_change_approvals', 'metadata_json')) {
                    $table->json('metadata_json')->nullable();
                }
            });
        }

        if (! Schema::hasTable('plm_cad_import_sessions')) {
            Schema::create('plm_cad_import_sessions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->string('session_code', 40);
                $table->uuid('product_id')->nullable()->index();
                $table->uuid('target_revision_id')->nullable()->index();
                $table->uuid('target_mbom_id')->nullable()->index();
                $table->string('cad_source_system', 32);
                $table->json('source_file_metadata');
                $table->json('parsed_part_list_json');
                $table->unsignedInteger('new_parts_to_create_count')->default(0);
                $table->json('created_parts_json')->nullable();
                $table->json('changed_quantities_diff_list')->nullable();
                $table->json('removed_parts_list')->nullable();
                $table->json('side_by_side_diff_json')->nullable();
                $table->json('cost_rollup_comparison_json')->nullable();
                $table->json('validation_warnings_json')->nullable();
                $table->string('status', 24)->default('Parsed')->index();
                $table->uuid('created_by')->nullable();
                $table->dateTime('committed_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'session_code']);
            });
        }

        if (! Schema::hasTable('plm_technical_dossiers')) {
            Schema::create('plm_technical_dossiers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('mfg_item_id')->index();
                $table->uuid('revision_id')->nullable()->index();
                $table->string('dossier_code', 60);
                $table->string('strategy_code', 60)->default('ce_ukca_machinery_rohs');
                $table->string('machinery_directive_ref', 120)->default('2006/42/EC + EU 2023/1230 + CH OMacch (RS 819.14)');
                $table->string('ce_declaration_status', 24)->default('compliant');
                $table->string('ukca_declaration_status', 24)->default('compliant');
                $table->boolean('omacch_mra_ch_eu_compliant')->default(true);
                $table->boolean('rohs_compliant')->default(false);
                $table->boolean('reach_svhc_compliant')->default(false);
                $table->boolean('conflict_minerals_compliant')->default(false);
                $table->json('iso_audit_standards_json')->nullable();
                $table->json('checklist_items_json')->nullable();
                $table->json('bom_compliance_rollup_json')->nullable();
                $table->dateTime('last_evaluated_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'dossier_code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plm_technical_dossiers');
        Schema::dropIfExists('plm_cad_import_sessions');

        if (Schema::hasTable('plm_change_approvals')) {
            Schema::table('plm_change_approvals', function (Blueprint $table): void {
                $cols = array_filter(['department', 'actor_role', 'sign_off_hash', 'metadata_json'], static fn (string $c): bool => Schema::hasColumn('plm_change_approvals', $c));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('plm_affected_items')) {
            Schema::table('plm_affected_items', function (Blueprint $table): void {
                $cols = array_filter(['from_revision_id', 'target_revision_id', 'disposition_rule', 'cutover_mode', 'effective_date', 'lot_serial_cutover', 'quarantined_lots_count', 'mes_drawings_obsoleted_count'], static fn (string $c): bool => Schema::hasColumn('plm_affected_items', $c));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('plm_change_orders')) {
            Schema::table('plm_change_orders', function (Blueprint $table): void {
                $cols = array_filter(['workflow_stage', 'change_reason', 'is_safety_immediate_cutover', 'existing_stock_disposition_rule', 'effective_date_or_lot_cutover', 'lot_serial_cutover_code', 'approvals_matrix_json', 'ecn_number', 'ecn_released_at', 'cutover_summary_json'], static fn (string $c): bool => Schema::hasColumn('plm_change_orders', $c));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }

        if (Schema::hasTable('plm_item_revisions')) {
            Schema::table('plm_item_revisions', function (Blueprint $table): void {
                $cols = array_filter([
                    'parent_revision_id',
                    'engineering_part_number',
                    'description',
                    'lifecycle_state',
                    'ebom_structure_json',
                    'mbom_id',
                    'routing_id',
                    'cad_drawing_pdf_and_step_file_ids',
                    'rohs_and_reach_compliant',
                    'conflict_minerals_compliant',
                    'technical_file_ref',
                    'regulatory_metadata_json',
                    'mes_drawing_obsolete_watermark',
                    'superseded_by_revision_id',
                    'audit_trail_json',
                ], static fn (string $c): bool => Schema::hasColumn('plm_item_revisions', $c));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });
        }
    }
};
