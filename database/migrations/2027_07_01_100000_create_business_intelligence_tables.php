<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business intelligence on top of the semantic layer of the Analytics module: calculated measures, saved pivot
     * queries, dashboards with widgets, shares and snapshots. New tables only. Nothing here stores SQL: a query is the
     * validated specification of the semantic layer, and business rules (grid without overlaps, owner-only sharing,
     * snapshot retention) are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('bi_calculated_measures')) {
            Schema::create('bi_calculated_measures', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('dataset', 40);
                $table->string('key', 40);
                $table->string('label', 120);
                $table->string('formula', 500);
                $table->string('unit', 20)->default('number');
                $table->unsignedTinyInteger('scale')->default(2);
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'dataset', 'key']);
            });
        }

        if (! Schema::hasTable('bi_saved_queries')) {
            Schema::create('bi_saved_queries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('owner_id')->index();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('dataset', 40);
                $table->json('spec');
                $table->json('calculated_measures')->nullable();
                $table->json('pivot')->nullable();
                $table->string('visibility', 20)->default('private');
                $table->string('snapshot_frequency', 20)->nullable();
                $table->date('next_snapshot_on')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'owner_id']);
                $table->index(['tenant_id', 'snapshot_frequency', 'next_snapshot_on'], 'bi_saved_queries_snapshot_due');
            });
        }

        if (! Schema::hasTable('bi_dashboards')) {
            Schema::create('bi_dashboards', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('owner_id')->index();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('visibility', 20)->default('private');
                $table->json('filters')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'owner_id']);
            });
        }

        if (! Schema::hasTable('bi_dashboard_widgets')) {
            Schema::create('bi_dashboard_widgets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('dashboard_id')->constrained('bi_dashboards')->cascadeOnDelete();
                $table->uuid('saved_query_id')->nullable()->index();
                $table->string('title', 160);
                $table->string('widget_type', 20);
                $table->string('dataset', 40)->nullable();
                $table->json('spec')->nullable();
                $table->json('calculated_measures')->nullable();
                $table->json('config')->nullable();
                $table->unsignedSmallInteger('layout_x')->default(0);
                $table->unsignedSmallInteger('layout_y')->default(0);
                $table->unsignedSmallInteger('layout_w')->default(6);
                $table->unsignedSmallInteger('layout_h')->default(4);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'dashboard_id']);
            });
        }

        if (! Schema::hasTable('bi_shares')) {
            Schema::create('bi_shares', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('resource_type', 20);
                $table->uuid('resource_id');
                $table->uuid('user_id')->index();
                $table->string('permission', 10)->default('view');
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'resource_type', 'resource_id'], 'bi_shares_resource');
            });
        }

        if (! Schema::hasTable('bi_snapshots')) {
            Schema::create('bi_snapshots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('source_type', 20);
                $table->uuid('source_id');
                $table->uuid('taken_by')->nullable();
                $table->date('taken_on');
                $table->string('trigger', 20)->default('manual');
                $table->string('status', 20)->default('ok');
                $table->text('error')->nullable();
                $table->unsignedInteger('row_count')->default(0);
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'source_type', 'source_id', 'taken_on'], 'bi_snapshots_source');
            });
        }
    }

    public function down(): void
    {
        foreach (['bi_snapshots', 'bi_shares', 'bi_dashboard_widgets', 'bi_dashboards', 'bi_saved_queries', 'bi_calculated_measures'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
