<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Product lifecycle management: engineering change orders with an approval flow, the revisions of manufacturing
     * items and the bills of material and items every change affects. New tables only; the approval and release rules
     * live in the application.
     */
    public function up(): void
    {
        if (! Schema::hasTable('plm_change_orders')) {
            Schema::create('plm_change_orders', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('title', 160);
                $table->text('reason');
                $table->string('change_type', 16)->default('design');
                $table->string('priority', 12)->default('normal');
                $table->string('status', 12)->default('draft');
                $table->uuid('requested_by')->nullable();
                $table->date('effective_date')->nullable();
                $table->dateTime('submitted_at')->nullable();
                $table->dateTime('decided_at')->nullable();
                $table->dateTime('implemented_at')->nullable();
                $table->text('rejection_reason')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('plm_change_approvals')) {
            Schema::create('plm_change_approvals', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('change_order_id')->constrained('plm_change_orders')->cascadeOnDelete();
                $table->foreignUuid('approver_id')->constrained('users')->cascadeOnDelete();
                $table->unsignedSmallInteger('sequence');
                $table->string('status', 12)->default('pending');
                $table->dateTime('decided_at')->nullable();
                $table->text('comment')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['change_order_id', 'sequence']);
            });
        }

        if (! Schema::hasTable('plm_item_revisions')) {
            Schema::create('plm_item_revisions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('mfg_item_id')->index();
                $table->string('revision', 20);
                $table->string('status', 12)->default('draft');
                $table->text('change_summary')->nullable();
                $table->uuid('change_order_id')->nullable()->index();
                $table->date('effective_from')->nullable();
                $table->dateTime('released_at')->nullable();
                $table->dateTime('obsoleted_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'mfg_item_id', 'status']);
            });
        }

        if (! Schema::hasTable('plm_affected_items')) {
            Schema::create('plm_affected_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('change_order_id')->constrained('plm_change_orders')->cascadeOnDelete();
                $table->uuid('mfg_item_id')->index();
                $table->string('to_revision', 20);
                $table->text('change_summary')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('plm_affected_boms')) {
            Schema::create('plm_affected_boms', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('change_order_id')->constrained('plm_change_orders')->cascadeOnDelete();
                $table->uuid('bom_id')->index();
                $table->text('note')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plm_affected_boms');
        Schema::dropIfExists('plm_affected_items');
        Schema::dropIfExists('plm_item_revisions');
        Schema::dropIfExists('plm_change_approvals');
        Schema::dropIfExists('plm_change_orders');
    }
};
