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
            $table->string('phone_e164', 20)->nullable()->index();
            $table->string('vat_number', 30)->nullable()->index();
            $table->string('company_normalized', 200)->nullable()->index();
            $table->string('industry', 100)->nullable();
            $table->string('region', 100)->nullable();
            $table->string('product_line', 100)->nullable();
            $table->unsignedInteger('company_size')->nullable();
            $table->decimal('estimated_revenue', 15, 2)->nullable();
            $table->unsignedInteger('email_opens')->default(0);
            $table->unsignedInteger('catalog_downloads')->default(0);
            $table->unsignedInteger('site_visits')->default(0);
            $table->jsonb('score_breakdown')->nullable();
            $table->uuid('duplicate_of_id')->nullable()->index();
            $table->timestamp('assigned_at')->nullable();
        });

        Schema::create('crm_lead_webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lead_source_id')->nullable()->constrained('crm_lead_sources')->nullOnDelete();
            $table->string('name', 150);
            $table->string('provider', 30)->default('generic'); // generic, wordpress, webflow, meta_ads, linkedin
            $table->uuid('token')->unique();
            $table->text('secret'); // encrypted
            $table->string('duplicate_strategy', 10)->default('notify'); // merge, notify, create
            $table->jsonb('field_map')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_received_at')->nullable();
            $table->unsignedBigInteger('received_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('crm_lead_routing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('strategy', 20)->default('round_robin'); // geographic, product_line, company_size, round_robin
            $table->unsignedInteger('priority')->default(100);
            $table->jsonb('criteria')->nullable(); // {countries:[], regions:[], product_lines:[], min_size, max_size}
            $table->jsonb('assignee_user_ids'); // rotation team
            $table->unsignedInteger('rotation_cursor')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active', 'priority']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->uuidMorphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_routing_rules');
        Schema::dropIfExists('crm_lead_webhook_endpoints');

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropColumn([
                'phone_e164', 'vat_number', 'company_normalized', 'industry', 'region', 'product_line',
                'company_size', 'estimated_revenue', 'email_opens', 'catalog_downloads', 'site_visits',
                'score_breakdown', 'duplicate_of_id', 'assigned_at',
            ]);
        });
    }
};
