<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 035: multiple sales pipelines (per business line / market), structured loss reasons, stage history, Kanban
 * ordering and quick deal notes. Dialect-agnostic (SQLite locally, PostgreSQL in CI and production).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_pipelines')) {
            Schema::create('crm_pipelines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 50);
                $table->string('name', 150);
                $table->string('business_line', 100)->nullable();
                $table->string('market', 50)->nullable();
                $table->string('currency', 3)->default('CHF');
                $table->json('translations')->nullable();
                $table->unsignedInteger('sequence')->default(10);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'is_active', 'sequence']);
            });
        }

        if (! Schema::hasTable('crm_loss_reasons')) {
            Schema::create('crm_loss_reasons', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 50);
                $table->string('name', 150);
                $table->json('translations')->nullable();
                $table->boolean('requires_competitor')->default(false);
                $table->unsignedInteger('sequence')->default(10);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->unique(['tenant_id', 'code']);
            });
        }

        Schema::table('crm_opportunity_stages', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_opportunity_stages', 'pipeline_id')) {
                // No database foreign key: SQLite cannot add one to an existing table; the tenant-scoped services own the link.
                $table->uuid('pipeline_id')->nullable()->index();
                $table->string('forecast_category', 30)->default('pipeline');
                $table->json('translations')->nullable();
            }
        });

        Schema::table('crm_opportunities', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_opportunities', 'pipeline_id')) {
                $table->uuid('pipeline_id')->nullable()->index();
                $table->unsignedInteger('board_position')->default(0);
                $table->string('loss_reason_code', 50)->nullable()->index();
                $table->text('loss_notes')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('notes_updated_at')->nullable();
            }
        });

        if (! Schema::hasTable('crm_opportunity_stage_history')) {
            Schema::create('crm_opportunity_stage_history', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('opportunity_id')->constrained('crm_opportunities')->cascadeOnDelete();
                $table->foreignUuid('from_stage_id')->nullable()->constrained('crm_opportunity_stages')->nullOnDelete();
                $table->foreignUuid('to_stage_id')->nullable()->constrained('crm_opportunity_stages')->nullOnDelete();
                $table->uuid('changed_by_user_id')->nullable()->index();
                $table->decimal('amount', 15, 2)->default(0);
                $table->decimal('probability', 5, 2)->default(0);
                $table->decimal('weighted_amount', 15, 2)->default(0);
                $table->string('loss_reason_code', 50)->nullable();
                $table->string('winning_competitor', 150)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();

                $table->index(['tenant_id', 'opportunity_id', 'created_at'], 'crm_opp_stage_hist_lookup');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunity_stage_history');

        if (Schema::hasColumn('crm_opportunities', 'pipeline_id')) {
            Schema::table('crm_opportunities', function (Blueprint $table): void {
                $table->dropIndex(['pipeline_id']);
                $table->dropIndex(['loss_reason_code']);
                $table->dropColumn(['pipeline_id', 'board_position', 'loss_reason_code', 'loss_notes', 'notes', 'notes_updated_at']);
            });
        }

        if (Schema::hasColumn('crm_opportunity_stages', 'pipeline_id')) {
            Schema::table('crm_opportunity_stages', function (Blueprint $table): void {
                $table->dropIndex(['pipeline_id']);
                $table->dropColumn(['pipeline_id', 'forecast_category', 'translations']);
            });
        }

        Schema::dropIfExists('crm_loss_reasons');
        Schema::dropIfExists('crm_pipelines');
    }
};
