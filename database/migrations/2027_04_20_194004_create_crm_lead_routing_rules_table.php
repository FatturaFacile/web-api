<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_lead_routing_rules')) {
            Schema::create('crm_lead_routing_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 150);
                $table->unsignedInteger('priority')->default(100);
                $table->json('criteria')->nullable();
                $table->json('conditions')->nullable();
                $table->string('strategy', 20)->default('round_robin');
                $table->json('assignee_user_ids');
                $table->unsignedInteger('rotation_cursor')->default(0);
                $table->unsignedInteger('round_robin_cursor')->default(0);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
                $table->string('created_by')->nullable();
                $table->string('updated_by')->nullable();
                $table->string('deleted_by')->nullable();

                $table->index(['tenant_id', 'is_active', 'priority']);
            });
        } else {
            Schema::table('crm_lead_routing_rules', function (Blueprint $table): void {
                if (! Schema::hasColumn('crm_lead_routing_rules', 'conditions')) {
                    $table->json('conditions')->nullable();
                }
                if (! Schema::hasColumn('crm_lead_routing_rules', 'round_robin_cursor')) {
                    $table->unsignedInteger('round_robin_cursor')->default(0);
                }
                if (! Schema::hasColumn('crm_lead_routing_rules', 'is_default')) {
                    $table->boolean('is_default')->default(false);
                }
            });
        }
    }

    public function down(): void
    {
        // Additive compatibility migration.
    }
};
