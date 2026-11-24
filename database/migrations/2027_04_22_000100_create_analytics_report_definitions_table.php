<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Saved report definitions of the Analytics module: which indicators, over which period, compared with what. A
     * definition belongs to a user and can be shared with the rest of the company.
     */
    public function up(): void
    {
        if (Schema::hasTable('analytics_report_definitions')) {
            return;
        }

        Schema::create('analytics_report_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->jsonb('kpis')->default('[]');
            $table->string('period_preset', 30)->default('month_to_date');
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->string('compare', 20)->default('none');
            $table->boolean('is_shared')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'is_shared'], 'idx_analytics_definitions_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_report_definitions');
    }
};
