<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk lifecycle: impact and urgency, tags, merge and split links, reopen counter, SLA pause, watchers, the activity
 * history of a ticket and the helpdesk settings of a tenant. Additive and nullable or defaulted, safe on populated tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esm_tickets', function (Blueprint $table): void {
            $table->string('impact', 20)->nullable();
            $table->string('urgency', 20)->nullable();
            $table->jsonb('tags')->nullable();
            $table->foreignUuid('merged_into_ticket_id')->nullable()->constrained('esm_tickets')->nullOnDelete();
            $table->foreignUuid('split_from_ticket_id')->nullable()->constrained('esm_tickets')->nullOnDelete();
            $table->unsignedSmallInteger('reopened_count')->default(0);
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('sla_paused_at')->nullable();
            $table->unsignedInteger('sla_paused_minutes')->default(0);
            $table->timestamp('customer_last_read_at')->nullable();
            $table->index(['tenant_id', 'merged_into_ticket_id']);
            $table->index(['tenant_id', 'status', 'assigned_technician_id']);
        });

        DB::statement('CREATE INDEX esm_tickets_tags_gin ON esm_tickets USING GIN (tags)');

        // Queues: the last agent a team handed a ticket to in round robin, and the team's default strategy.
        Schema::table('esm_service_teams', function (Blueprint $table): void {
            $table->uuid('round_robin_cursor')->nullable();
            $table->string('assignment_strategy', 20)->default('manual');
        });

        // SLA clocks reuse the holiday calendar of HR when a business hours calendar points to one.
        Schema::table('esm_business_hours_calendars', function (Blueprint $table): void {
            $table->foreignUuid('hr_holiday_calendar_id')->nullable()->constrained('hr_holiday_calendars')->nullOnDelete();
        });

        Schema::create('esm_ticket_watchers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('esm_tickets')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id']);
            $table->unique(['ticket_id', 'user_id']);
        });

        Schema::create('esm_ticket_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('esm_tickets')->cascadeOnDelete();
            $table->uuid('actor_user_id')->nullable();
            $table->string('type', 40);
            $table->string('from_value', 120)->nullable();
            $table->string('to_value', 120)->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'ticket_id', 'created_at']);
            $table->index(['tenant_id', 'type', 'created_at']);
        });

        Schema::create('esm_helpdesk_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('reopen_window_days')->default(7);
            $table->foreignUuid('business_hours_calendar_id')->nullable()->constrained('esm_business_hours_calendars')->nullOnDelete();
            $table->unsignedSmallInteger('sla_warning_percent')->default(80);
            $table->boolean('csat_enabled')->default(true);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_helpdesk_settings');
        Schema::dropIfExists('esm_ticket_activities');
        Schema::dropIfExists('esm_ticket_watchers');
        DB::statement('DROP INDEX IF EXISTS esm_tickets_tags_gin');

        Schema::table('esm_business_hours_calendars', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('hr_holiday_calendar_id');
        });

        Schema::table('esm_service_teams', function (Blueprint $table): void {
            $table->dropColumn(['round_robin_cursor', 'assignment_strategy']);
        });

        Schema::table('esm_tickets', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'merged_into_ticket_id']);
            $table->dropIndex(['tenant_id', 'status', 'assigned_technician_id']);
            $table->dropConstrainedForeignId('merged_into_ticket_id');
            $table->dropConstrainedForeignId('split_from_ticket_id');
            $table->dropColumn(['impact', 'urgency', 'tags', 'reopened_count', 'status_changed_at', 'sla_paused_at', 'sla_paused_minutes', 'customer_last_read_at']);
        });
    }
};
