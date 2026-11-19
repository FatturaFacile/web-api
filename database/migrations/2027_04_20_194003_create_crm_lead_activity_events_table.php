<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_lead_activity_events')) {
            return;
        }

        Schema::create('crm_lead_activity_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->string('type', 50);
            $table->timestamp('occurred_at');
            $table->json('metadata')->nullable();
            $table->string('external_id', 191)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();

            $table->unique(['lead_id', 'external_id'], 'crm_lead_activity_events_lead_external_unique');
            $table->index(['tenant_id', 'lead_id', 'type', 'occurred_at'], 'crm_lead_activity_events_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_activity_events');
    }
};
