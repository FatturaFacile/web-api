<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_lead_scoring_rules')) {
            Schema::create('crm_lead_scoring_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 50)->nullable();
                $table->string('name', 150);
                $table->string('rule_type')->default('field');
                $table->string('category', 20)->nullable();
                $table->string('field', 100)->nullable();
                $table->string('event_type', 50)->nullable();
                $table->string('operator', 20)->default('exists');
                $table->json('value')->nullable();
                $table->string('activity_type')->nullable();
                $table->string('activity_status')->nullable();
                $table->integer('points');
                $table->integer('max_points')->nullable();
                $table->unsignedInteger('max_applications')->nullable();
                $table->unsignedInteger('decay_days')->nullable();
                $table->integer('sequence')->default(10);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->string('deleted_by')->nullable();

                $table->index(['tenant_id', 'is_active', 'category']);
                $table->index(['tenant_id', 'code']);
            });
        } else {
            Schema::table('crm_lead_scoring_rules', function (Blueprint $table): void {
                if (! Schema::hasColumn('crm_lead_scoring_rules', 'code')) {
                    $table->string('code', 50)->nullable();
                }
                if (! Schema::hasColumn('crm_lead_scoring_rules', 'category')) {
                    $table->string('category', 20)->nullable();
                }
                if (! Schema::hasColumn('crm_lead_scoring_rules', 'event_type')) {
                    $table->string('event_type', 50)->nullable();
                }
                if (! Schema::hasColumn('crm_lead_scoring_rules', 'max_applications')) {
                    $table->unsignedInteger('max_applications')->nullable();
                }
                if (! Schema::hasColumn('crm_lead_scoring_rules', 'decay_days')) {
                    $table->unsignedInteger('decay_days')->nullable();
                }
            });
        }

        if (! Schema::hasTable('crm_lead_settings')) {
            Schema::create('crm_lead_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->unsignedSmallInteger('warm_threshold')->default(40);
                $table->unsignedSmallInteger('hot_threshold')->default(70);
                $table->unsignedSmallInteger('auto_qualify_threshold')->default(80);
                $table->string('dedup_mode', 20)->default('notify');
                $table->unsignedSmallInteger('dedup_levenshtein_max')->default(2);
                $table->decimal('dedup_similarity_min', 4, 3)->default(0.85);
                $table->string('default_country', 2)->default('IT');
                $table->timestamps();
                $table->softDeletes();
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->string('deleted_by')->nullable();

                $table->unique('tenant_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_settings');
    }
};
