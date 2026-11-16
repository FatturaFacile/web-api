<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_routing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedInteger('priority')->default(100);
            // {countries[], regions[], product_lines[], employee_min, employee_max, ratings[]}
            $table->jsonb('conditions')->nullable();
            $table->string('strategy', 20)->default('round_robin'); // round_robin, fixed
            $table->jsonb('assignee_user_ids');
            $table->unsignedInteger('round_robin_cursor')->default(0);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_routing_rules');
    }
};
