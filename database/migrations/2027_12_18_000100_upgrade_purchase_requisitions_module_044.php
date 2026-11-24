<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->uuid('requester_user_id')->nullable()->index();
            $table->date('requested_delivery_date')->nullable();
            $table->text('economic_justification')->nullable();
            $table->json('budget_check_snapshot')->nullable();
            $table->boolean('budget_warning_acknowledged')->default(false);
            $table->unsignedSmallInteger('current_approval_level')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('converted_at')->nullable();

            $table->foreign('project_id', 'fk_pr_project_id')
                ->references('id')
                ->on('projects')
                ->nullOnDelete();
        });

        Schema::table('purchase_requisition_lines', function (Blueprint $table): void {
            $table->string('item_type', 20)->default('free_text');
            $table->uuid('catalog_item_id')->nullable()->index();
            $table->decimal('quantity', 12, 4)->nullable();
            $table->string('unit_of_measure', 20)->nullable();
            $table->decimal('estimated_line_total', 14, 2)->nullable();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->uuid('project_id')->nullable()->index();
            $table->decimal('converted_quantity', 12, 4)->default(0);
            $table->string('conversion_status', 30)->default('unconverted')->index();

            $table->foreign('project_id', 'fk_pr_lines_project_id')
                ->references('id')
                ->on('projects')
                ->nullOnDelete();
        });

        Schema::create('purchase_requisition_approval_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('purchase_requisition_id')
                ->constrained('purchase_requisitions')
                ->cascadeOnDelete();
            $table->unsignedSmallInteger('approval_step_level')->default(0);
            $table->uuid('approver_user_id')->nullable()->index();
            $table->string('action', 32);
            $table->string('channel', 32)->default('web');
            $table->text('comment')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('acted_at');
            $table->timestampTz('created_at')->nullable();

            $table->index(['purchase_requisition_id', 'acted_at'], 'idx_pr_approval_logs_pr_acted');
            $table->index(['tenant_id', 'purchase_requisition_id'], 'idx_pr_approval_logs_tenant_pr');
        });

        Schema::create('purchase_requisition_document_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('purchase_requisition_id')
                ->constrained('purchase_requisitions')
                ->cascadeOnDelete();
            $table->foreignUuid('purchase_requisition_line_id')
                ->constrained('purchase_requisition_lines')
                ->cascadeOnDelete();
            $table->string('target_document_type', 32);
            $table->uuid('target_document_id')->index();
            $table->string('target_document_number', 100);
            $table->uuid('target_document_line_id')->nullable()->index();
            $table->decimal('converted_quantity', 12, 4);
            $table->uuid('created_by_user_id')->nullable()->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'purchase_requisition_id'], 'idx_pr_doc_links_tenant_pr');
            $table->index(['purchase_requisition_line_id'], 'idx_pr_doc_links_pr_line');
            $table->index(['tenant_id', 'target_document_type', 'target_document_id'], 'idx_pr_doc_links_target');
        });

        Schema::table('procurement_settings', function (Blueprint $table): void {
            $table->string('budget_enforcement_policy', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('procurement_settings', function (Blueprint $table): void {
            $table->dropColumn(['budget_enforcement_policy']);
        });

        Schema::dropIfExists('purchase_requisition_document_links');
        Schema::dropIfExists('purchase_requisition_approval_logs');

        Schema::table('purchase_requisition_lines', function (Blueprint $table): void {
            $table->dropForeign('fk_pr_lines_project_id');
            $table->dropIndex(['catalog_item_id']);
            $table->dropIndex(['cost_center_id']);
            $table->dropIndex(['project_id']);
            $table->dropIndex(['conversion_status']);
            $table->dropColumn([
                'item_type',
                'catalog_item_id',
                'quantity',
                'unit_of_measure',
                'estimated_line_total',
                'cost_center_id',
                'project_id',
                'converted_quantity',
                'conversion_status',
            ]);
        });

        Schema::table('purchase_requisitions', function (Blueprint $table): void {
            $table->dropForeign('fk_pr_project_id');
            $table->dropIndex(['requester_user_id']);
            $table->dropColumn([
                'requester_user_id',
                'requested_delivery_date',
                'economic_justification',
                'budget_check_snapshot',
                'budget_warning_acknowledged',
                'current_approval_level',
                'rejected_at',
                'rejection_reason',
                'cancelled_at',
                'converted_at',
            ]);
        });
    }
};
