<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crm_lead_duplicate_candidates')) {
            return;
        }

        Schema::create('crm_lead_duplicate_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lead_id')->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignUuid('candidate_lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('account_id')->nullable()->constrained('crm_accounts')->cascadeOnDelete();
            $table->string('match_type', 20);
            $table->decimal('confidence', 5, 4)->default(1);
            $table->string('status', 20)->default('pending');
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->string('deleted_by')->nullable();

            $table->index(['tenant_id', 'status', 'created_at'], 'crm_lead_duplicate_candidates_status_index');
            $table->index(['lead_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_duplicate_candidates');
    }
};
