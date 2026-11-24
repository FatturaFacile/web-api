<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk SLA and escalation (Mod 083, #195, #43): escalation chains with threshold steps and an immutable log of the
 * steps fired on a ticket, the next-update target, Swiss and Italian holiday sets on the business hours calendars,
 * the locale of canned responses and knowledge base articles, and the contract or billing outcome of the time logged
 * on a ticket. Additive, nullable or defaulted, safe on populated tables and dialect agnostic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esm_escalation_chains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('esm_escalation_steps', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('chain_id')->constrained('esm_escalation_chains')->cascadeOnDelete();
            // response, next_update or resolution: the SLA target whose elapsed share triggers the step.
            $table->string('target', 20);
            // Share of the target elapsed: 75 warns before the breach, 100 is the breach, above 100 escalates after it.
            $table->unsignedSmallInteger('threshold_percent');
            // notify, reassign_team or raise_priority.
            $table->string('action', 20);
            $table->jsonb('notify_user_ids')->nullable();
            $table->boolean('notify_team_lead')->default(false);
            $table->uuid('team_id')->nullable();
            $table->string('priority', 20)->nullable();
            $table->unsignedSmallInteger('position')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'chain_id', 'target']);
        });

        // Immutable audit: one row per step fired on a ticket, so a step never fires twice.
        Schema::create('esm_ticket_escalation_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('esm_tickets')->cascadeOnDelete();
            $table->uuid('chain_id');
            $table->uuid('step_id');
            $table->string('target', 20);
            $table->unsignedSmallInteger('threshold_percent');
            $table->string('action', 20);
            $table->unsignedSmallInteger('elapsed_percent');
            // The next-update target restarts after each agent reply: its due date tells the cycles apart, empty otherwise.
            $table->string('cycle_key', 40)->default('');
            $table->jsonb('outcome')->nullable();
            $table->timestamp('fired_at');
            $table->timestamps();

            $table->unique(['ticket_id', 'step_id', 'cycle_key']);
            $table->index(['tenant_id', 'ticket_id']);
        });

        Schema::table('esm_sla_policies', function (Blueprint $table): void {
            $table->uuid('escalation_chain_id')->nullable();
            $table->unsignedInteger('next_update_interval_minutes')->nullable();
            $table->boolean('pause_on_on_hold')->default(false);
        });

        Schema::table('esm_tickets', function (Blueprint $table): void {
            $table->timestamp('next_update_due_at')->nullable();
            $table->timestamp('next_update_breached_at')->nullable();
            $table->index(['tenant_id', 'next_update_due_at']);
        });

        Schema::table('esm_business_hours_calendars', function (Blueprint $table): void {
            $table->string('holiday_country', 2)->nullable();
            $table->string('holiday_region', 8)->nullable();
        });

        Schema::table('esm_canned_responses', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable();
        });

        Schema::table('esm_knowledge_articles', function (Blueprint $table): void {
            $table->string('locale', 5)->nullable();
            $table->index(['tenant_id', 'locale']);
        });

        Schema::table('esm_rma_orders', function (Blueprint $table): void {
            $table->uuid('ticket_id')->nullable();
            $table->index(['tenant_id', 'ticket_id']);
        });

        Schema::table('esm_ticket_time_entries', function (Blueprint $table): void {
            $table->uuid('service_contract_id')->nullable();
            $table->uuid('contract_transaction_id')->nullable();
            $table->decimal('contract_hours', 6, 2)->default(0);
            $table->decimal('billable_hours', 6, 2)->default(0);
            // none, contract_deducted, partially_deducted, billable_pending or non_billable.
            $table->string('billing_status', 24)->default('none');
        });
    }

    public function down(): void
    {
        Schema::table('esm_rma_orders', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'ticket_id']);
            $table->dropColumn('ticket_id');
        });

        Schema::table('esm_ticket_time_entries', function (Blueprint $table): void {
            $table->dropColumn(['service_contract_id', 'contract_transaction_id', 'contract_hours', 'billable_hours', 'billing_status']);
        });

        Schema::table('esm_knowledge_articles', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'locale']);
            $table->dropColumn('locale');
        });

        Schema::table('esm_canned_responses', function (Blueprint $table): void {
            $table->dropColumn('locale');
        });

        Schema::table('esm_business_hours_calendars', function (Blueprint $table): void {
            $table->dropColumn(['holiday_country', 'holiday_region']);
        });

        Schema::table('esm_tickets', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'next_update_due_at']);
            $table->dropColumn(['next_update_due_at', 'next_update_breached_at']);
        });

        Schema::table('esm_sla_policies', function (Blueprint $table): void {
            $table->dropColumn(['escalation_chain_id', 'next_update_interval_minutes', 'pause_on_on_hold']);
        });

        Schema::dropIfExists('esm_ticket_escalation_logs');
        Schema::dropIfExists('esm_escalation_steps');
        Schema::dropIfExists('esm_escalation_chains');
    }
};
