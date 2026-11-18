<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_leads', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_leads', 'vat_number')) {
                $table->string('vat_number', 30)->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'phone_e164')) {
                $table->string('phone_e164', 20)->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'company_name_normalized')) {
                $table->string('company_name_normalized', 200)->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'industry')) {
                $table->string('industry', 100)->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'employee_count')) {
                $table->unsignedInteger('employee_count')->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'annual_revenue_estimate')) {
                $table->decimal('annual_revenue_estimate', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'state_region')) {
                $table->string('state_region', 50)->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'product_line')) {
                $table->string('product_line', 100)->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'external_id')) {
                $table->string('external_id', 191)->nullable();
                $table->unique(['tenant_id', 'lead_source_id', 'external_id'], 'crm_leads_tenant_source_external_unique');
            }
            if (! Schema::hasColumn('crm_leads', 'score_breakdown')) {
                $table->json('score_breakdown')->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'scored_at')) {
                $table->timestamp('scored_at')->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'last_activity_at')) {
                $table->timestamp('last_activity_at')->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'merged_into_lead_id')) {
                $table->foreignUuid('merged_into_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();
            }
        });

        Schema::table('crm_lead_sources', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_lead_sources', 'type')) {
                $table->string('type', 30)->default('manual');
            }
            if (! Schema::hasColumn('crm_lead_sources', 'api_key_prefix')) {
                $table->string('api_key_prefix', 16)->nullable()->index();
            }
            if (! Schema::hasColumn('crm_lead_sources', 'api_key_hash')) {
                $table->string('api_key_hash', 64)->nullable();
            }
            if (! Schema::hasColumn('crm_lead_sources', 'webhook_secret')) {
                $table->text('webhook_secret')->nullable();
            }
            if (! Schema::hasColumn('crm_lead_sources', 'allowed_origins')) {
                $table->json('allowed_origins')->nullable();
            }
            if (! Schema::hasColumn('crm_lead_sources', 'field_mapping')) {
                $table->json('field_mapping')->nullable();
            }
            if (! Schema::hasColumn('crm_lead_sources', 'default_product_line')) {
                $table->string('default_product_line', 100)->nullable();
            }
            if (! Schema::hasColumn('crm_lead_sources', 'last_received_at')) {
                $table->timestamp('last_received_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Additive compatibility migration.
    }
};
