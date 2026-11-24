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
        // 1. Work Orders (Dispatch & Field Execution Aggregate)
        if (! Schema::hasTable('esm_work_orders')) {
            Schema::create('esm_work_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('ticket_id')->nullable()->constrained('esm_tickets')->nullOnDelete();
                $table->uuid('customer_id')->index(); // CRM Customer
                $table->uuid('asset_id')->nullable()->index(); // Module 16 Asset
                $table->uuid('project_id')->nullable()->index(); // Module 15 Project
                $table->foreignUuid('service_team_id')->nullable()->constrained('esm_service_teams')->nullOnDelete();
                $table->foreignUuid('assigned_technician_id')->nullable()->constrained('esm_service_technicians')->nullOnDelete();
                $table->string('work_order_number', 60)->index();
                $table->string('title', 200);
                $table->text('description')->nullable();
                $table->string('work_type', 40)->default('corrective'); // corrective, preventive, installation, inspection, emergency
                $table->string('priority', 20)->default('medium'); // low, medium, high, critical
                $table->string('status', 30)->default('draft')->index(); // draft, scheduled, dispatched, in_progress, completed, cancelled

                // Scheduling & Execution Times
                $table->timestamp('scheduled_start')->nullable()->index();
                $table->timestamp('scheduled_end')->nullable()->index();
                $table->timestamp('actual_start')->nullable();
                $table->timestamp('actual_end')->nullable();

                // Cost & Financials
                $table->decimal('labor_cost', 18, 2)->default(0.00);
                $table->decimal('material_cost', 18, 2)->default(0.00);
                $table->decimal('expense_cost', 18, 2)->default(0.00);
                $table->decimal('total_cost', 18, 2)->default(0.00);
                $table->decimal('total_billable_amount', 18, 2)->default(0.00);
                $table->string('currency', 3)->default('EUR');

                $table->boolean('is_billable')->default(true);
                $table->boolean('is_invoiced')->default(false);
                $table->uuid('invoice_id')->nullable()->index();

                // Customer Sign-off
                $table->boolean('customer_signed')->default(false);
                $table->string('signed_by_name', 150)->nullable();
                $table->text('signature_data')->nullable(); // signature token or base64
                $table->timestamp('signed_at')->nullable();

                // Workflow & Concurrency
                $table->uuid('workflow_instance_id')->nullable()->index();
                $table->boolean('is_locked')->default(false);

                $table->text('resolution_notes')->nullable();
                $table->jsonb('location')->nullable(); // {address, lat, lng}
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'work_order_number']);
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement("CREATE INDEX esm_work_orders_active_schedule_idx ON esm_work_orders (tenant_id, assigned_technician_id, scheduled_start) WHERE status IN ('scheduled', 'dispatched', 'in_progress') AND deleted_at IS NULL");
                DB::statement('CREATE INDEX esm_work_orders_location_gin ON esm_work_orders USING GIN (location)');
            }
        }

        // 2. Work Order Items (Labor, Parts, Expenses)
        if (! Schema::hasTable('esm_work_order_items')) {
            Schema::create('esm_work_order_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('work_order_id')->constrained('esm_work_orders')->cascadeOnDelete();
                $table->string('item_type', 30)->default('labor'); // labor, part, expense
                $table->uuid('product_id')->nullable()->index(); // Module 14 Inventory product
                $table->string('description', 255);
                $table->decimal('quantity', 12, 4)->default(1.0000);
                $table->decimal('unit_cost', 18, 2)->default(0.00);
                $table->decimal('unit_price', 18, 2)->default(0.00);
                $table->decimal('total_cost', 18, 2)->default(0.00);
                $table->decimal('total_price', 18, 2)->default(0.00);
                $table->boolean('is_billable')->default(true);
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 3. Service Appointments (Field Technician Scheduling)
        if (! Schema::hasTable('esm_service_appointments')) {
            Schema::create('esm_service_appointments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->string('appointment_number', 60)->index();
                $table->foreignUuid('work_order_id')->constrained('esm_work_orders')->cascadeOnDelete();
                $table->foreignUuid('technician_id')->constrained('esm_service_technicians')->cascadeOnDelete();
                $table->uuid('customer_id')->index();
                $table->timestamp('start_time')->index();
                $table->timestamp('end_time')->index();
                $table->string('status', 30)->default('scheduled')->index(); // scheduled, confirmed, in_transit, arrived, completed, missed, cancelled
                $table->unsignedInteger('route_order')->default(1);
                $table->text('notes')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'appointment_number']);
            });
        }

        // 4. Service Visits (Physical On-Site Execution)
        if (! Schema::hasTable('esm_service_visits')) {
            Schema::create('esm_service_visits', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('appointment_id')->nullable()->constrained('esm_service_appointments')->nullOnDelete();
                $table->foreignUuid('work_order_id')->constrained('esm_work_orders')->cascadeOnDelete();
                $table->foreignUuid('technician_id')->constrained('esm_service_technicians')->cascadeOnDelete();
                $table->timestamp('check_in_at')->nullable();
                $table->timestamp('check_out_at')->nullable();
                $table->unsignedInteger('duration_minutes')->default(0);
                $table->boolean('customer_present')->default(true);
                $table->boolean('is_successful')->default(true);
                $table->text('visit_summary')->nullable();
                $table->jsonb('location_check_in')->nullable();
                $table->jsonb('location_check_out')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 5. Parts Consumptions (Warehouse / Mobile Van Material Tracking)
        if (! Schema::hasTable('esm_parts_consumptions')) {
            Schema::create('esm_parts_consumptions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('work_order_id')->constrained('esm_work_orders')->cascadeOnDelete();
                $table->uuid('product_id')->index(); // Inventory Product
                $table->uuid('warehouse_id')->nullable()->index(); // Inventory Warehouse / Mobile Van
                $table->decimal('quantity', 12, 4);
                $table->decimal('unit_cost', 18, 2)->default(0.00);
                $table->decimal('total_cost', 18, 2)->default(0.00);
                $table->string('lot_number', 60)->nullable();
                $table->jsonb('serial_numbers')->nullable();
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 6. Customer Satisfaction (CSAT) Surveys & Feedback
        if (! Schema::hasTable('esm_csat_surveys')) {
            Schema::create('esm_csat_surveys', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('ticket_id')->nullable()->constrained('esm_tickets')->nullOnDelete();
                $table->foreignUuid('work_order_id')->nullable()->constrained('esm_work_orders')->nullOnDelete();
                $table->uuid('customer_id')->index();
                $table->unsignedTinyInteger('score')->default(5); // 1 to 5 stars
                $table->string('sentiment', 20)->default('positive'); // positive, neutral, negative
                $table->text('feedback')->nullable();
                $table->timestamp('responded_at');
                $table->jsonb('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_csat_surveys');
        Schema::dropIfExists('esm_parts_consumptions');
        Schema::dropIfExists('esm_service_visits');
        Schema::dropIfExists('esm_service_appointments');
        Schema::dropIfExists('esm_work_order_items');
        Schema::dropIfExists('esm_work_orders');
    }
};
