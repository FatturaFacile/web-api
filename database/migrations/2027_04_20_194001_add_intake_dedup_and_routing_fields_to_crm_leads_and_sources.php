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
            $table->string('vat_number', 30)->nullable();
            $table->string('phone_e164', 20)->nullable();
            $table->string('company_name_normalized', 200)->nullable();
            $table->string('industry', 100)->nullable();
            $table->unsignedInteger('employee_count')->nullable();
            $table->decimal('annual_revenue_estimate', 15, 2)->nullable();
            $table->string('state_region', 50)->nullable(); // Province (IT) or canton (CH) code
            $table->string('product_line', 100)->nullable();
            $table->string('external_id', 191)->nullable();
            $table->jsonb('score_breakdown')->nullable();
            $table->timestamp('scored_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignUuid('merged_into_lead_id')->nullable()->constrained('crm_leads')->nullOnDelete();

            $table->unique(['tenant_id', 'lead_source_id', 'external_id'], 'crm_leads_tenant_source_external_unique');
            $table->index(['tenant_id', 'phone_e164'], 'crm_leads_tenant_phone_e164_index');
            $table->index(['tenant_id', 'vat_number'], 'crm_leads_tenant_vat_number_index');
            $table->index(['tenant_id', 'company_name_normalized'], 'crm_leads_tenant_company_normalized_index');
            $table->index(['tenant_id', 'assigned_user_id', 'rating'], 'crm_leads_tenant_assignee_rating_index');
        });

        Schema::table('crm_lead_sources', function (Blueprint $table): void {
            // web_form, wordpress, webflow, meta_lead_ads, linkedin_lead_gen, manual, csv
            $table->string('type', 30)->default('manual');
            $table->string('api_key_prefix', 16)->nullable()->index();
            $table->string('api_key_hash', 64)->nullable();
            $table->text('webhook_secret')->nullable(); // encrypted cast on the model
            $table->jsonb('allowed_origins')->nullable();
            $table->jsonb('field_mapping')->nullable();
            $table->string('default_product_line', 100)->nullable();
            $table->timestamp('last_received_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_lead_sources', function (Blueprint $table): void {
            $table->dropIndex(['api_key_prefix']);
            $table->dropColumn([
                'type',
                'api_key_prefix',
                'api_key_hash',
                'webhook_secret',
                'allowed_origins',
                'field_mapping',
                'default_product_line',
                'last_received_at',
            ]);
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropForeign(['merged_into_lead_id']);
            $table->dropUnique('crm_leads_tenant_source_external_unique');
            $table->dropIndex('crm_leads_tenant_phone_e164_index');
            $table->dropIndex('crm_leads_tenant_vat_number_index');
            $table->dropIndex('crm_leads_tenant_company_normalized_index');
            $table->dropIndex('crm_leads_tenant_assignee_rating_index');
            $table->dropColumn([
                'vat_number',
                'phone_e164',
                'company_name_normalized',
                'industry',
                'employee_count',
                'annual_revenue_estimate',
                'state_region',
                'product_line',
                'external_id',
                'score_breakdown',
                'scored_at',
                'last_activity_at',
                'merged_into_lead_id',
            ]);
        });
    }
};
