<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module 18 HR - Recruiting & ATS (Mod 070): job offer letters, stage email templates,
 * candidate email log and per-tenant recruiting settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Job Offer Letters
        if (! Schema::hasTable('rec_job_offers')) {
            Schema::create('rec_job_offers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('application_id')->constrained('rec_candidate_applications')->cascadeOnDelete();
                $table->decimal('proposed_salary', 18, 2);
                $table->string('currency', 3)->default('EUR');
                $table->date('start_date');
                $table->string('employment_type', 40)->default('full_time');
                $table->string('contract_type', 40)->default('permanent');
                $table->decimal('weekly_hours', 6, 2)->default(40.00);
                $table->unsignedSmallInteger('probation_months')->default(3);
                $table->date('valid_until')->nullable();
                $table->string('status', 20)->default('draft')->index(); // draft, sent, signed, declined, expired, cancelled
                $table->string('signature_status', 20)->default('not_requested'); // not_requested, pending, signed, declined
                $table->string('signature_provider', 40)->default('manual');
                $table->string('signature_external_id', 120)->nullable();
                $table->longText('rendered_body')->nullable();
                $table->string('locale', 10)->default('en');
                $table->text('decline_note')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('signed_at')->nullable();
                $table->timestamp('declined_at')->nullable();
                $table->uuid('created_by')->nullable()->index();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        // 2. Stage Email Templates (per tenant, stage and locale)
        if (! Schema::hasTable('rec_email_templates')) {
            Schema::create('rec_email_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('stage', 40);
                $table->string('locale', 10);
                $table->string('subject', 255);
                $table->text('body');
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'stage', 'locale']);
            });
        }

        // 3. Candidate Email Log (every attempt, including skipped ones)
        if (! Schema::hasTable('rec_candidate_emails')) {
            Schema::create('rec_candidate_emails', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('application_id')->constrained('rec_candidate_applications')->cascadeOnDelete();
                $table->string('stage', 40);
                $table->string('locale', 10)->nullable();
                $table->string('recipient_email', 180)->nullable();
                $table->string('subject', 255)->nullable();
                $table->string('status', 20)->default('queued'); // queued, sent, failed, skipped
                $table->string('failure_reason', 255)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['application_id', 'created_at']);
            });
        }

        // 4. Recruiting Settings (one row per tenant)
        if (! Schema::hasTable('rec_settings')) {
            Schema::create('rec_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
                $table->string('careers_slug', 60)->nullable()->unique();
                $table->boolean('careers_enabled')->default(false);
                $table->string('company_name', 150)->nullable();
                $table->jsonb('intro')->nullable();
                $table->jsonb('privacy_notice')->nullable();
                $table->string('privacy_policy_url', 255)->nullable();
                $table->string('consent_text_version', 60)->default('v1');
                $table->unsignedSmallInteger('retention_default_months')->default(6);
                $table->jsonb('pipeline')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX rec_settings_intro_gin ON rec_settings USING GIN (intro)');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rec_settings');
        Schema::dropIfExists('rec_candidate_emails');
        Schema::dropIfExists('rec_email_templates');
        Schema::dropIfExists('rec_job_offers');
    }
};
