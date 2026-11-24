<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Affiliation of a body to a parent body. The row belongs to the affiliate, which is the only side that can grant
     * or withdraw consent; the parent sees aggregated figures only while the consent stands.
     */
    public function up(): void
    {
        Schema::create('assoc_network_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('parent_tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('consent_granted_at')->nullable();
            $table->uuid('consent_granted_by')->nullable();
            $table->timestamp('consent_withdrawn_at')->nullable();
            $table->uuid('consent_withdrawn_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['parent_tenant_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX assoc_network_links_unique ON assoc_network_links (tenant_id, parent_tenant_id) WHERE deleted_at IS NULL');
            DB::statement('ALTER TABLE assoc_network_links ADD CONSTRAINT assoc_network_links_distinct_check CHECK (tenant_id <> parent_tenant_id)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_network_links');
    }
};
