<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive columns of the single subscription engine (modules/19_SubscriptionBilling): billing alignment, trials, term
 * and renewal rules, pauses, cancellations with reasons, the price factor of renewals, the links between billing
 * periods, real invoices, revenue schedules and dunning, and the legacy source ids of the consolidation.
 *
 * Data: the invoice schedules written before the engine issued real invoices point at invoice ids that do not exist;
 * they are marked `synthetic` (never deleted) so the one-invoice-per-period index below holds only real billings.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->addColumns('sub_plans', [
            'tax_rate' => fn (Blueprint $table) => $table->decimal('tax_rate', 5, 2)->nullable(),
            'billing_alignment' => fn (Blueprint $table) => $table->string('billing_alignment', 20)->default('anniversary'),
            'renewal_price_increase_percent' => fn (Blueprint $table) => $table->decimal('renewal_price_increase_percent', 7, 4)->default(0),
            'revenue_account_id' => fn (Blueprint $table) => $table->uuid('revenue_account_id')->nullable(),
            'deferred_revenue_account_id' => fn (Blueprint $table) => $table->uuid('deferred_revenue_account_id')->nullable(),
        ]);

        $this->addColumns('sub_subscriptions', [
            'name' => fn (Blueprint $table) => $table->string('name', 255)->nullable(),
            'legacy_subscription_id' => fn (Blueprint $table) => $table->uuid('legacy_subscription_id')->nullable()->unique(),
            'billing_alignment' => fn (Blueprint $table) => $table->string('billing_alignment', 20)->default('anniversary'),
            'billing_anchor_date' => fn (Blueprint $table) => $table->date('billing_anchor_date')->nullable(),
            'term_end_date' => fn (Blueprint $table) => $table->date('term_end_date')->nullable(),
            'trial_end_date' => fn (Blueprint $table) => $table->date('trial_end_date')->nullable(),
            'cancel_at_period_end' => fn (Blueprint $table) => $table->boolean('cancel_at_period_end')->default(false),
            'cancellation_type' => fn (Blueprint $table) => $table->string('cancellation_type', 20)->nullable(),
            'cancellation_reason_code' => fn (Blueprint $table) => $table->string('cancellation_reason_code', 40)->nullable(),
            'cancellation_effective_date' => fn (Blueprint $table) => $table->date('cancellation_effective_date')->nullable(),
            'paused_at' => fn (Blueprint $table) => $table->timestamp('paused_at')->nullable(),
            'pause_reason' => fn (Blueprint $table) => $table->string('pause_reason', 255)->nullable(),
            'resume_on' => fn (Blueprint $table) => $table->date('resume_on')->nullable(),
            'renewal_notice_days' => fn (Blueprint $table) => $table->unsignedSmallInteger('renewal_notice_days')->default(30),
            'renewal_notified_at' => fn (Blueprint $table) => $table->timestamp('renewal_notified_at')->nullable(),
            'renewal_price_increase_percent' => fn (Blueprint $table) => $table->decimal('renewal_price_increase_percent', 7, 4)->nullable(),
            'last_billed_at' => fn (Blueprint $table) => $table->timestamp('last_billed_at')->nullable(),
            'notes' => fn (Blueprint $table) => $table->text('notes')->nullable(),
        ]);

        $this->addColumns('sub_subscription_items', [
            'tax_rate' => fn (Blueprint $table) => $table->decimal('tax_rate', 5, 2)->nullable(),
            'price_factor' => fn (Blueprint $table) => $table->decimal('price_factor', 14, 8)->default(1),
            'legacy_item_id' => fn (Blueprint $table) => $table->uuid('legacy_item_id')->nullable()->index(),
        ]);

        $this->addColumns('sub_invoice_schedules', [
            'billing_kind' => fn (Blueprint $table) => $table->string('billing_kind', 20)->default('recurring'),
            'billing_run_id' => fn (Blueprint $table) => $table->uuid('billing_run_id')->nullable()->index(),
            'credit_note_id' => fn (Blueprint $table) => $table->uuid('credit_note_id')->nullable()->index(),
            'net_amount' => fn (Blueprint $table) => $table->decimal('net_amount', 15, 2)->default(0),
            'error' => fn (Blueprint $table) => $table->text('error')->nullable(),
        ]);

        $this->addColumns('sub_usage_aggregations', [
            'invoice_schedule_id' => fn (Blueprint $table) => $table->uuid('invoice_schedule_id')->nullable()->index(),
        ]);

        $this->addColumns('sub_performance_obligations', [
            'invoice_schedule_id' => fn (Blueprint $table) => $table->uuid('invoice_schedule_id')->nullable()->index(),
            'source_document_type' => fn (Blueprint $table) => $table->string('source_document_type', 20)->nullable(),
            'source_document_id' => fn (Blueprint $table) => $table->uuid('source_document_id')->nullable()->index(),
        ]);

        $this->addColumns('sub_dunning_policies', [
            'max_collection_retries' => fn (Blueprint $table) => $table->unsignedSmallInteger('max_collection_retries')->default(3),
            'retry_interval_days' => fn (Blueprint $table) => $table->unsignedSmallInteger('retry_interval_days')->default(3),
        ]);

        $this->addColumns('sub_dunning_records', [
            'dunning_policy_id' => fn (Blueprint $table) => $table->uuid('dunning_policy_id')->nullable(),
            'retry_count' => fn (Blueprint $table) => $table->unsignedSmallInteger('retry_count')->default(0),
            'next_retry_at' => fn (Blueprint $table) => $table->timestamp('next_retry_at')->nullable()->index(),
            'last_failure_reason' => fn (Blueprint $table) => $table->string('last_failure_reason', 255)->nullable(),
            'last_action_at' => fn (Blueprint $table) => $table->timestamp('last_action_at')->nullable(),
            'days_overdue' => fn (Blueprint $table) => $table->unsignedInteger('days_overdue')->default(0),
        ]);

        // Schedules of the former placeholder billing: their invoice ids were never issued documents.
        DB::table('sub_invoice_schedules')
            ->where('status', 'invoiced')
            ->whereNotNull('invoice_id')
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('invoices')->whereColumn('invoices.id', 'sub_invoice_schedules.invoice_id'))
            ->update(['status' => 'synthetic', 'updated_at' => now()]);

        // Several open dunning cases of one invoice (seeded data): the latest stays open, the others are superseded.
        $duplicated = DB::table('sub_dunning_records')->where('status', 'active')->groupBy('invoice_id')->havingRaw('COUNT(*) > 1')->pluck('invoice_id');

        foreach ($duplicated as $invoiceId) {
            $keep = DB::table('sub_dunning_records')->where('status', 'active')->where('invoice_id', $invoiceId)->orderByDesc('created_at')->orderByDesc('id')->value('id');
            DB::table('sub_dunning_records')->where('status', 'active')->where('invoice_id', $invoiceId)->where('id', '!=', $keep)->update(['status' => 'superseded', 'updated_at' => now()]);
        }

        if (DB::connection()->getDriverName() === 'pgsql') {
            // One recurring invoice per subscription and service period: the last defence of idempotent billing.
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS sub_invoice_schedules_one_recurring_per_period ON sub_invoice_schedules (subscription_id, service_period_start) WHERE billing_kind = 'recurring' AND status IN ('processing', 'invoiced')");
            // One open dunning case per invoice.
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS sub_dunning_records_one_active_per_invoice ON sub_dunning_records (invoice_id) WHERE status = 'active'");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS sub_invoice_schedules_one_recurring_per_period');
            DB::statement('DROP INDEX IF EXISTS sub_dunning_records_one_active_per_invoice');
        }

        $columns = [
            'sub_plans' => ['tax_rate', 'billing_alignment', 'renewal_price_increase_percent', 'revenue_account_id', 'deferred_revenue_account_id'],
            'sub_subscriptions' => ['name', 'legacy_subscription_id', 'billing_alignment', 'billing_anchor_date', 'term_end_date', 'trial_end_date', 'cancel_at_period_end', 'cancellation_type', 'cancellation_reason_code', 'cancellation_effective_date', 'paused_at', 'pause_reason', 'resume_on', 'renewal_notice_days', 'renewal_notified_at', 'renewal_price_increase_percent', 'last_billed_at', 'notes'],
            'sub_subscription_items' => ['tax_rate', 'price_factor', 'legacy_item_id'],
            'sub_invoice_schedules' => ['billing_kind', 'billing_run_id', 'credit_note_id', 'net_amount', 'error'],
            'sub_usage_aggregations' => ['invoice_schedule_id'],
            'sub_performance_obligations' => ['invoice_schedule_id', 'source_document_type', 'source_document_id'],
            'sub_dunning_policies' => ['max_collection_retries', 'retry_interval_days'],
            'sub_dunning_records' => ['dunning_policy_id', 'retry_count', 'next_retry_at', 'last_failure_reason', 'last_action_at', 'days_overdue'],
        ];

        foreach ($columns as $table => $names) {
            $existing = array_values(array_filter($names, fn (string $name): bool => Schema::hasColumn($table, $name)));

            if ($existing !== []) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($existing));
            }
        }
    }

    /**
     * Adds each column only when it is missing, so the migration can run on a database that already has some of them.
     *
     * @param  array<string, Closure(Blueprint): mixed>  $columns
     */
    private function addColumns(string $table, array $columns): void
    {
        $missing = array_filter($columns, fn (string $name): bool => ! Schema::hasColumn($table, $name), ARRAY_FILTER_USE_KEY);

        if ($missing === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($missing): void {
            foreach ($missing as $definition) {
                $definition($blueprint);
            }
        });
    }
};
