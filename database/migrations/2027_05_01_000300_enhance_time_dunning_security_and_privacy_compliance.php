<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('time_night_compensation_balances')) {
            Schema::create('time_night_compensation_balances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->uuid('employee_id')->nullable()->index();
                $table->unsignedSmallInteger('year');
                $table->unsignedInteger('ytd_night_shifts')->default(0);
                $table->boolean('is_regular_night_worker')->default(false);
                $table->unsignedInteger('total_night_minutes')->default(0);
                $table->unsignedInteger('accrued_rest_minutes')->default(0);
                $table->unsignedInteger('taken_rest_minutes')->default(0);
                $table->integer('remaining_rest_minutes')->default(0);
                $table->unsignedInteger('cash_supplement_minutes')->default(0);
                $table->string('compensation_mode', 30)->default('cash_25_percent');
                $table->timestamps();

                $table->unique(['tenant_id', 'user_id', 'year'], 'time_night_comp_balances_tenant_user_year_unique');
            });
        }

        if (Schema::hasTable('time_recording_profiles') && ! Schema::hasColumn('time_recording_profiles', 'is_regular_night_worker')) {
            Schema::table('time_recording_profiles', function (Blueprint $table): void {
                $table->boolean('is_regular_night_worker')->default(false);
            });
        }

        if (Schema::hasTable('payment_reminders')) {
            Schema::table('payment_reminders', function (Blueprint $table): void {
                if (! Schema::hasColumn('payment_reminders', 'dunning_fee')) {
                    $table->decimal('dunning_fee', 12, 2)->default(0.00);
                }
                if (! Schema::hasColumn('payment_reminders', 'interest_rate_percent')) {
                    $table->decimal('interest_rate_percent', 5, 2)->default(5.00);
                }
                if (! Schema::hasColumn('payment_reminders', 'interest_amount')) {
                    $table->decimal('interest_amount', 12, 2)->default(0.00);
                }
                if (! Schema::hasColumn('payment_reminders', 'total_claimed_amount')) {
                    $table->decimal('total_claimed_amount', 12, 2)->default(0.00);
                }
                if (! Schema::hasColumn('payment_reminders', 'qr_bill_attached')) {
                    $table->boolean('qr_bill_attached')->default(false);
                }
                if (! Schema::hasColumn('payment_reminders', 'qr_reference')) {
                    $table->string('qr_reference')->nullable();
                }
            });
        }

        if (Schema::hasTable('contacts')) {
            Schema::table('contacts', function (Blueprint $table): void {
                if (! Schema::hasColumn('contacts', 'processing_restricted_at')) {
                    $table->timestamp('processing_restricted_at')->nullable();
                }
                if (! Schema::hasColumn('contacts', 'anonymized_at')) {
                    $table->timestamp('anonymized_at')->nullable();
                }
            });
        }

        if (Schema::hasTable('hr_employees')) {
            Schema::table('hr_employees', function (Blueprint $table): void {
                if (! Schema::hasColumn('hr_employees', 'processing_restricted_at')) {
                    $table->timestamp('processing_restricted_at')->nullable();
                }
                if (! Schema::hasColumn('hr_employees', 'anonymized_at')) {
                    $table->timestamp('anonymized_at')->nullable();
                }
            });
        }

        if (! Schema::hasTable('privacy_processing_activities')) {
            Schema::create('privacy_processing_activities', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('title');
                $table->text('purpose');
                $table->string('legal_basis', 60);
                $table->jsonb('data_categories');
                $table->jsonb('subject_categories');
                $table->jsonb('recipients')->nullable();
                $table->jsonb('cross_border_countries')->nullable();
                $table->string('transfer_safeguards', 80)->nullable();
                $table->string('retention_period', 120);
                $table->text('security_measures')->nullable();
                $table->string('status', 30)->default('active');
                $table->unsignedInteger('version')->default(1);
                $table->timestamps();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('privacy_subprocessors')) {
            Schema::create('privacy_subprocessors', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name');
                $table->string('service_type', 120);
                $table->string('country_code', 2);
                $table->boolean('is_adequate_country_nlpd')->default(true);
                $table->string('safeguard_mechanism', 80)->nullable();
                $table->date('dpa_signed_at')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('privacy_data_subject_requests')) {
            Schema::create('privacy_data_subject_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('request_type', 30);
                $table->string('regulation', 20)->default('nLPD');
                $table->string('subject_type', 30);
                $table->uuid('subject_id');
                $table->string('status', 40)->default('received');
                $table->timestamp('deadline_at');
                $table->timestamp('extended_until')->nullable();
                $table->text('extension_reason')->nullable();
                $table->jsonb('legal_holds')->nullable();
                $table->jsonb('erasure_summary')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('handled_by')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'subject_type', 'subject_id'], 'privacy_dsr_tenant_subject_index');
            });
        }

        if (! Schema::hasTable('privacy_data_breaches')) {
            Schema::create('privacy_data_breaches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('title');
                $table->string('severity', 20);
                $table->timestamp('discovered_at');
                $table->timestamp('notification_deadline_at');
                $table->timestamp('fdpic_notified_at')->nullable();
                $table->timestamp('gdpr_authority_notified_at')->nullable();
                $table->timestamp('subjects_notified_at')->nullable();
                $table->unsignedInteger('affected_records_count')->default(0);
                $table->jsonb('data_categories');
                $table->text('likely_consequences')->nullable();
                $table->text('mitigation_measures')->nullable();
                $table->boolean('requires_authority_notification')->default(false);
                $table->boolean('requires_subject_notification')->default(false);
                $table->string('status', 30)->default('investigating');
                $table->timestamps();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('privacy_sensitive_access_logs')) {
            Schema::create('privacy_sensitive_access_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('user_id')->nullable()->index();
                $table->string('subject_type', 40);
                $table->string('subject_id', 64);
                $table->string('data_category', 80);
                $table->string('action', 20);
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();

                $table->index(['tenant_id', 'subject_type', 'subject_id'], 'privacy_access_logs_subject_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_sensitive_access_logs');
        Schema::dropIfExists('privacy_data_breaches');
        Schema::dropIfExists('privacy_data_subject_requests');
        Schema::dropIfExists('privacy_subprocessors');
        Schema::dropIfExists('privacy_processing_activities');
        Schema::dropIfExists('time_night_compensation_balances');
    }
};
