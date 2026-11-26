<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_preferences')) {
            Schema::table('notification_preferences', function (Blueprint $table): void {
                if (! Schema::hasColumn('notification_preferences', 'tenant_id')) {
                    $table->foreignUuid('tenant_id')->nullable()->after('id')->constrained('tenants')->cascadeOnDelete();
                }
                if (! Schema::hasColumn('notification_preferences', 'custom_cron')) {
                    $table->string('custom_cron', 120)->nullable()->after('frequency');
                }
                if (! Schema::hasColumn('notification_preferences', 'send_time')) {
                    $table->string('send_time', 5)->default('07:00')->after('custom_cron');
                }
                if (! Schema::hasColumn('notification_preferences', 'day_of_week')) {
                    $table->unsignedTinyInteger('day_of_week')->nullable()->after('send_time');
                }
                if (! Schema::hasColumn('notification_preferences', 'day_of_month')) {
                    $table->unsignedTinyInteger('day_of_month')->nullable()->after('day_of_week');
                }
                if (! Schema::hasColumn('notification_preferences', 'language')) {
                    $table->string('language', 10)->default('it-CH')->after('day_of_month');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_dashboard')) {
                    $table->boolean('include_dashboard')->default(true)->after('language');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_billing')) {
                    $table->boolean('include_billing')->default(true)->after('include_dashboard');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_deadlines')) {
                    $table->boolean('include_deadlines')->default(true)->after('include_billing');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_crm')) {
                    $table->boolean('include_crm')->default(true)->after('include_deadlines');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_calendar')) {
                    $table->boolean('include_calendar')->default(true)->after('include_crm');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_inventory')) {
                    $table->boolean('include_inventory')->default(true)->after('include_calendar');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_support')) {
                    $table->boolean('include_support')->default(true)->after('include_inventory');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_news')) {
                    $table->boolean('include_news')->default(true)->after('include_support');
                }
                if (! Schema::hasColumn('notification_preferences', 'include_insights')) {
                    $table->boolean('include_insights')->default(true)->after('include_news');
                }
                if (! Schema::hasColumn('notification_preferences', 'last_sent_at')) {
                    $table->timestamp('last_sent_at')->nullable()->after('include_insights');
                }
                if (! Schema::hasColumn('notification_preferences', 'last_period_key')) {
                    $table->string('last_period_key', 64)->nullable()->after('last_sent_at');
                }
                if (! Schema::hasColumn('notification_preferences', 'unsubscribed_at')) {
                    $table->timestamp('unsubscribed_at')->nullable()->after('last_period_key');
                }

                $table->unique(['tenant_id', 'user_id'], 'notification_preferences_tenant_user_unique');
                $table->index(['tenant_id', 'enabled'], 'notification_preferences_tenant_enabled_idx');
            });
        }

        if (! Schema::hasTable('tenant_report_settings')) {
            Schema::create('tenant_report_settings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->unique()->constrained('tenants')->cascadeOnDelete();
                $table->string('timezone', 64)->default('Europe/Zurich');
                $table->string('default_frequency', 20)->default('daily');
                $table->string('default_send_time', 5)->default('07:00');
                $table->string('sender_name', 160)->nullable();
                $table->string('sender_email', 190)->nullable();
                $table->string('logo_url', 500)->nullable();
                $table->string('primary_color', 20)->default('#1890ff');
                $table->string('secondary_color', 20)->default('#001529');
                $table->text('custom_header_html')->nullable();
                $table->text('custom_footer_html')->nullable();
                $table->json('enabled_modules')->nullable();
                $table->unsignedInteger('rate_limit_per_hour')->default(1000);
                $table->timestamps();

                $table->index('tenant_id', 'tenant_report_settings_tenant_idx');
            });
        }

        if (! Schema::hasTable('email_blacklist')) {
            Schema::create('email_blacklist', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('email', 190)->index('email_blacklist_email_idx');
                $table->string('reason', 40)->default('manual');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'email'], 'email_blacklist_tenant_email_idx');
            });
        }

        if (! Schema::hasTable('scheduled_report_deliveries')) {
            Schema::create('scheduled_report_deliveries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->uuid('notification_preference_id')->nullable()->index('srd_pref_idx');
                $table->string('recipient_email', 190)->index('srd_email_idx');
                $table->string('frequency', 20)->default('daily');
                $table->string('period_key', 64)->index('srd_period_idx');
                $table->string('language', 10)->default('it-CH');
                $table->boolean('is_test')->default(false);
                $table->string('status', 30)->default('queued')->index('srd_status_idx');
                $table->string('message_id', 190)->nullable();
                $table->string('provider_status', 120)->nullable();
                $table->text('error_message')->nullable();
                $table->text('error_trace')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('clicked_at')->nullable();
                $table->unsignedInteger('open_count')->default(0);
                $table->unsignedInteger('click_count')->default(0);
                $table->timestamps();

                $table->index(['tenant_id', 'user_id'], 'srd_tenant_user_idx');
                $table->index(['tenant_id', 'created_at'], 'srd_tenant_created_idx');
            });
        }

        if (! Schema::hasTable('scheduled_report_audit_logs')) {
            Schema::create('scheduled_report_audit_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('user_id')->nullable()->index('sral_user_idx');
                $table->uuid('delivery_id')->nullable()->index('sral_delivery_idx');
                $table->string('event_type', 50)->index('sral_event_idx');
                $table->string('recipient_email', 190)->nullable();
                $table->string('period_key', 64)->nullable();
                $table->string('message_id', 190)->nullable();
                $table->string('provider_status', 120)->nullable();
                $table->string('ip_address', 64)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->text('error_trace')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->index('sral_occurred_idx');
                $table->timestamps();

                $table->index(['tenant_id', 'occurred_at'], 'sral_tenant_occurred_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_report_audit_logs');
        Schema::dropIfExists('scheduled_report_deliveries');
        Schema::dropIfExists('email_blacklist');
        Schema::dropIfExists('tenant_report_settings');

        if (Schema::hasTable('notification_preferences')) {
            Schema::table('notification_preferences', function (Blueprint $table): void {
                $table->dropUnique('notification_preferences_tenant_user_unique');
                $table->dropIndex('notification_preferences_tenant_enabled_idx');

                $columns = [
                    'tenant_id',
                    'custom_cron',
                    'send_time',
                    'day_of_week',
                    'day_of_month',
                    'language',
                    'include_dashboard',
                    'include_billing',
                    'include_deadlines',
                    'include_crm',
                    'include_calendar',
                    'include_inventory',
                    'include_support',
                    'include_news',
                    'include_insights',
                    'last_sent_at',
                    'last_period_key',
                    'unsubscribed_at',
                ];

                $existing = array_values(array_filter(
                    $columns,
                    static fn (string $col): bool => Schema::hasColumn('notification_preferences', $col)
                ));

                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
        }
    }
};
