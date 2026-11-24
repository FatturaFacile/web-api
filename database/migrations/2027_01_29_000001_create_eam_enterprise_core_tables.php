<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Asset Categories (Hierarchical with GL Account Mapping & Depreciation Presets)
        if (! Schema::hasTable('eam_asset_categories')) {
            Schema::create('eam_asset_categories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('parent_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('asset_type', 40)->default('tangible')->index(); // tangible, intangible, financial, real_estate, right_of_use
                $table->string('depreciation_method', 50)->default('straight_line'); // straight_line, declining_balance, double_declining_balance, units_of_production, custom
                $table->unsignedInteger('useful_life_years')->default(5);
                $table->unsignedInteger('useful_life_months')->default(60);
                $table->decimal('statutory_rate_percent', 6, 2)->default(20.00);
                $table->decimal('salvage_value_percent', 6, 2)->default(0.00);
                $table->string('gl_asset_account_code', 50)->default('1600');
                $table->string('gl_accumulated_depreciation_code', 50)->default('1650');
                $table->string('gl_depreciation_expense_code', 50)->default('6400');
                $table->string('gl_impairment_account_code', 50)->default('6450');
                $table->string('gl_revaluation_reserve_code', 50)->default('3300');
                $table->string('gl_disposal_gain_loss_code', 50)->default('7500');
                $table->string('gl_clearing_account_code', 50)->default('2150');
                $table->boolean('is_depreciable')->default(true);
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            Schema::table('eam_asset_categories', function (Blueprint $table): void {
                $table->foreign('parent_id')->references('id')->on('eam_asset_categories')->nullOnDelete();
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX eam_asset_categories_translations_gin ON eam_asset_categories USING GIN (translations)');
                DB::statement('CREATE INDEX eam_asset_categories_metadata_gin ON eam_asset_categories USING GIN (metadata)');
            }
        }

        // 2. Asset Classes (Operational & Maintenance Classification)
        if (! Schema::hasTable('eam_asset_classes')) {
            Schema::create('eam_asset_classes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('criticality', 30)->default('medium'); // low, medium, high, mission_critical
                $table->unsignedInteger('default_maintenance_interval_days')->default(180);
                $table->boolean('requires_custodian')->default(false);
                $table->boolean('requires_calibration')->default(false);
                $table->boolean('is_active')->default(true);
                $table->jsonb('translations')->nullable();
                $table->jsonb('technical_schema')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX eam_asset_classes_translations_gin ON eam_asset_classes USING GIN (translations)');
            }
        }

        // 3. Asset Locations (Hierarchical Physical Sites, Buildings, Rooms, Yards)
        if (! Schema::hasTable('eam_asset_locations')) {
            Schema::create('eam_asset_locations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('parent_id')->nullable()->index();
                $table->uuid('warehouse_id')->nullable()->index();
                $table->string('code', 60)->index();
                $table->string('name', 150);
                $table->string('location_type', 40)->default('facility'); // facility, building, floor, room, yard, mobile, project_site
                $table->string('address')->nullable();
                $table->string('city', 100)->nullable();
                $table->string('country', 5)->default('IT');
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->boolean('is_active')->default(true);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
            });

            Schema::table('eam_asset_locations', function (Blueprint $table): void {
                $table->foreign('parent_id')->references('id')->on('eam_asset_locations')->nullOnDelete();
            });
        }

        // 4. Fixed Assets Master & Lifecycle Aggregate Root
        if (! Schema::hasTable('eam_fixed_assets')) {
            Schema::create('eam_fixed_assets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('category_id')->constrained('eam_asset_categories')->restrictOnDelete();
                $table->foreignUuid('asset_class_id')->nullable()->constrained('eam_asset_classes')->nullOnDelete();
                $table->foreignUuid('location_id')->nullable()->constrained('eam_asset_locations')->nullOnDelete();
                $table->uuid('parent_asset_id')->nullable()->index();
                $table->foreignUuid('custodian_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('project_id')->nullable()->index();
                $table->uuid('supplier_id')->nullable()->index();
                $table->uuid('purchase_order_id')->nullable()->index();
                $table->uuid('purchase_receipt_id')->nullable()->index();
                $table->uuid('inventory_item_id')->nullable()->index();

                $table->string('asset_number', 60)->index();
                $table->string('barcode_tag', 100)->nullable()->index();
                $table->string('serial_number', 100)->nullable()->index();
                $table->string('name', 255);
                $table->text('description')->nullable();

                // Lifecycle Status & Condition
                $table->string('status', 40)->default('draft')->index();
                // draft, registered, capitalized, commissioned, in_service, active, under_maintenance, revalued, impaired, retired, disposed, sold
                $table->string('condition', 40)->default('new')->index();
                // new, excellent, good, fair, poor, damaged, decommissioned
                $table->string('acquisition_type', 40)->default('purchase');
                // purchase, inventory_conversion, cip_project, lease, donated, manual

                // Lifecycle Dates
                $table->date('acquisition_date')->nullable();
                $table->date('capitalization_date')->nullable();
                $table->date('commissioning_date')->nullable();
                $table->date('in_service_date')->nullable();
                $table->date('warranty_expiry_date')->nullable();
                $table->date('retirement_date')->nullable();
                $table->date('disposal_date')->nullable();

                // Financial & Valuation Values
                $table->string('currency', 3)->default('EUR');
                $table->decimal('acquisition_cost', 15, 2)->default(0.00);
                $table->decimal('capitalized_cost', 15, 2)->default(0.00);
                $table->decimal('salvage_value', 15, 2)->default(0.00);
                $table->decimal('depreciable_base', 15, 2)->default(0.00);
                $table->decimal('accumulated_depreciation', 15, 2)->default(0.00);
                $table->decimal('impairment_loss_total', 15, 2)->default(0.00);
                $table->decimal('revaluation_surplus_total', 15, 2)->default(0.00);
                $table->decimal('carrying_amount', 15, 2)->default(0.00);
                $table->decimal('replacement_cost', 15, 2)->default(0.00);

                // Depreciation Engine Parameters
                $table->string('depreciation_method', 50)->default('straight_line');
                $table->unsignedInteger('useful_life_years')->default(5);
                $table->unsignedInteger('useful_life_months')->default(60);
                $table->decimal('depreciation_rate_percent', 6, 2)->default(20.00);
                $table->decimal('total_expected_units', 15, 2)->default(0.00);
                $table->decimal('consumed_units', 15, 2)->default(0.00);
                $table->date('last_depreciation_date')->nullable();
                $table->boolean('is_depreciable')->default(true);
                $table->boolean('is_fully_depreciated')->default(false);

                // Workflow & Concurrency
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->string('approval_status', 40)->default('not_required')->index();
                $table->boolean('is_locked')->default(false);

                $table->jsonb('specifications')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'asset_number']);
            });

            Schema::table('eam_fixed_assets', function (Blueprint $table): void {
                $table->foreign('parent_asset_id')->references('id')->on('eam_fixed_assets')->nullOnDelete();
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX eam_fixed_assets_specifications_gin ON eam_fixed_assets USING GIN (specifications)');
                DB::statement('CREATE INDEX eam_fixed_assets_metadata_gin ON eam_fixed_assets USING GIN (metadata)');
                DB::statement('ALTER TABLE eam_fixed_assets ADD CONSTRAINT chk_eam_fixed_assets_costs CHECK (acquisition_cost >= 0 AND capitalized_cost >= 0 AND salvage_value >= 0 AND accumulated_depreciation >= 0)');
            }
        }

        // 5. Asset Components (IAS 16 Componentization & Sub-Assemblies)
        if (! Schema::hasTable('eam_asset_components')) {
            Schema::create('eam_asset_components', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('parent_asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->foreignUuid('component_asset_id')->nullable()->constrained('eam_fixed_assets')->nullOnDelete();
                $table->string('component_code', 60);
                $table->string('name', 150);
                $table->string('serial_number', 100)->nullable();
                $table->decimal('cost_allocation', 15, 2)->default(0.00);
                $table->unsignedInteger('useful_life_months')->default(36);
                $table->string('condition', 40)->default('new');
                $table->string('status', 40)->default('active'); // active, replaced, detached, failed
                $table->date('installed_date')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 6. Asset Custodians (Employee & Department Custody Ledger)
        if (! Schema::hasTable('eam_asset_custodians')) {
            Schema::create('eam_asset_custodians', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->foreignUuid('custodian_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignUuid('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('department', 100)->nullable();
                $table->timestamp('assigned_at');
                $table->timestamp('expected_return_at')->nullable();
                $table->timestamp('returned_at')->nullable();
                $table->string('condition_on_checkout', 40)->default('good');
                $table->string('condition_on_return', 40)->nullable();
                $table->string('status', 30)->default('active')->index(); // active, returned, lost, transferred
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 7. Asset Documents (Manuals, Warranties, Insurance, Certifications)
        if (! Schema::hasTable('eam_asset_documents')) {
            Schema::create('eam_asset_documents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->string('document_type', 50)->index(); // manual, warranty, insurance, invoice, inspection, cad_bim, license
                $table->string('title', 200);
                $table->string('document_number', 100)->nullable();
                $table->string('file_path', 500)->nullable();
                $table->date('issue_date')->nullable();
                $table->date('expiry_date')->nullable()->index();
                $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 8. Asset Relationships (Topology, Dependencies, Redundancy)
        if (! Schema::hasTable('eam_asset_relationships')) {
            Schema::create('eam_asset_relationships', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('source_asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->foreignUuid('target_asset_id')->constrained('eam_fixed_assets')->cascadeOnDelete();
                $table->string('relationship_type', 50)->index(); // depends_on, powers, connected_to, replaces, backup_for, part_of
                $table->boolean('is_critical_dependency')->default(false);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'source_asset_id', 'target_asset_id', 'relationship_type'], 'eam_asset_rel_unique');
            });
        }
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy
    }
};
