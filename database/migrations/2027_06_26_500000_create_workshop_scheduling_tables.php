<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workshop scheduling: service bays and the planned slots of a repair folder (technician, bay and time window).
     * Overlap rules are enforced by the application, not by the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('workshop_bays')) {
            Schema::create('workshop_bays', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 30);
                $table->string('name', 120);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('workshop_job_slots')) {
            Schema::create('workshop_job_slots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('folder_id')->constrained('workshop_folders')->cascadeOnDelete();
                $table->uuid('technician_user_id')->nullable();
                $table->uuid('bay_id')->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('status', 20)->default('planned');
                $table->string('notes', 500)->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'starts_at']);
                $table->index(['tenant_id', 'technician_user_id', 'starts_at']);
                $table->index(['tenant_id', 'bay_id', 'starts_at']);
                $table->index(['tenant_id', 'folder_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_job_slots');
        Schema::dropIfExists('workshop_bays');
    }
};
