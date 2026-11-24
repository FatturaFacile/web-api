<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access control: readers authenticated by a hashed secret, who is inside each area, and the access log.
     */
    public function up(): void
    {
        Schema::create('assoc_access_devices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('area_id')->constrained('assoc_access_areas')->restrictOnDelete();
            $table->string('name');
            $table->string('device_key', 40)->unique();
            $table->string('secret_hash', 64);
            $table->string('direction', 10)->default('in');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'area_id']);
        });

        Schema::create('assoc_area_presences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('area_id')->constrained('assoc_access_areas')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->timestamp('entered_at');
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'area_id', 'left_at']);
        });

        // Like the audit ledger, no foreign keys: nothing may cascade into this table.
        Schema::create('assoc_access_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('device_id');
            $table->uuid('area_id');
            $table->uuid('member_id')->nullable();
            $table->uuid('credential_id')->nullable();
            $table->string('direction', 10);
            $table->string('decision', 10);
            $table->string('reason_code', 40);
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'member_id', 'occurred_at']);
            $table->index(['tenant_id', 'area_id', 'occurred_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX assoc_area_presences_open_unique ON assoc_area_presences (area_id, member_id) WHERE left_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_access_logs');
        Schema::dropIfExists('assoc_area_presences');
        Schema::dropIfExists('assoc_access_devices');
    }
};
