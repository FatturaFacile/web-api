<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 003 (issue #249) — multi-currency engine: the exchange-rate table gets its rate type, publisher, derived flag, version and
 * correction link (a rate is never edited, a correction is a new version the old one points to); the runs of the ECB / SNB / ESTV
 * synchronisation; the currency settings of a company; the period-end revaluations with their lines; and the realised exchange
 * differences of the settled items.
 *
 * A rate is unique per owner, pair, type, publisher, day and version: the ECB and the SNB each keep their own rate of a pair and day,
 * and the selection prefers the manual rate, then the ECB, the SNB and the ESTV.
 *
 * Columns of exchange_rates keep their historical names: `source` is the base currency and `target` the quote currency (1 source
 * = rate target), `effective_date` the day the rate is valid on; `rate_source` is who published it. Dialect-agnostic: runs
 * identically on SQLite and PostgreSQL. A row without tenant is an official rate shared by every company.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->extendExchangeRates();
        $this->syncRuns();
        $this->settings();
        $this->revaluations();
        $this->realizedDifferences();
    }

    public function down(): void
    {
        foreach (['currency_realized_fx_differences', 'currency_fx_revaluation_lines', 'currency_fx_revaluations', 'currency_settings', 'exchange_rate_sync_runs'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropUnique('exchange_rates_scope_pair_type_day_version_unique');
        });

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->unique(['source', 'target', 'effective_date']);
            $table->dropColumn(['rate_type', 'rate_source', 'is_derived', 'version', 'superseded_by', 'reason', 'contract_reference']);
        });
    }

    private function extendExchangeRates(): void
    {
        if (! Schema::hasColumn('exchange_rates', 'rate_type')) {
            Schema::table('exchange_rates', function (Blueprint $table): void {
                $table->string('rate_type', 20)->default('mid');
                $table->string('rate_source', 10)->default('manual');
                $table->boolean('is_derived')->default(false);
                $table->unsignedSmallInteger('version')->default(1);
                $table->uuid('superseded_by')->nullable()->index();
                $table->text('reason')->nullable();
                $table->string('contract_reference', 80)->nullable()->index();
            });
        }

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->dropUnique(['source', 'target', 'effective_date']);
        });

        Schema::table('exchange_rates', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'source', 'target', 'rate_type', 'rate_source', 'effective_date', 'version'], 'exchange_rates_scope_pair_type_day_version_unique');
            $table->index(['source', 'target', 'rate_type', 'effective_date'], 'exchange_rates_lookup_index');
        });
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function syncRuns(): void
    {
        if (Schema::hasTable('exchange_rate_sync_runs')) {
            return;
        }

        Schema::create('exchange_rate_sync_runs', function (Blueprint $table): void {
            $this->base($table);
            $table->string('provider', 10);
            $table->string('status', 12)->default('queued');
            $table->string('trigger', 12)->default('scheduled');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('rates_count')->default(0);
            $table->unsignedInteger('derived_count')->default(0);
            $table->date('latest_rate_date')->nullable();
            $table->text('error_message')->nullable();
            $table->uuid('requested_by')->nullable();
            $this->audit($table);

            $table->index(['provider', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    private function settings(): void
    {
        if (Schema::hasTable('currency_settings')) {
            return;
        }

        Schema::create('currency_settings', function (Blueprint $table): void {
            $this->base($table);
            $table->json('active_currencies');
            $table->decimal('alert_threshold_percent', 6, 2)->nullable()->default('2.00');
            $table->string('unrealized_gain_treatment', 12)->default('recognise');
            $table->string('vat_rate_method', 12)->nullable();
            $table->timestamp('vat_method_changed_at')->nullable();
            $table->uuid('unrealized_gain_account_id')->nullable();
            $table->uuid('unrealized_loss_account_id')->nullable();
            $table->uuid('unrealized_gain_provision_account_id')->nullable();
            $this->audit($table);

            $table->unique('tenant_id');
        });
    }

    private function revaluations(): void
    {
        if (Schema::hasTable('currency_fx_revaluations')) {
            return;
        }

        Schema::create('currency_fx_revaluations', function (Blueprint $table): void {
            $this->base($table);
            $table->date('period_end');
            $table->string('period_type', 12);
            $table->string('status', 12)->default('draft');
            $table->string('functional_currency', 3);
            $table->json('currencies')->nullable();
            $table->decimal('total_gain', 18, 2)->default(0);
            $table->decimal('total_loss', 18, 2)->default(0);
            $table->decimal('net_result', 18, 2)->default(0);
            $table->decimal('deferred_gain', 18, 2)->default(0);
            $table->decimal('reserve_amount', 18, 2)->default(0);
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('reversal_journal_entry_id')->nullable();
            $table->date('reverse_on')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'period_end', 'status']);
            $table->index(['status', 'reverse_on']);
        });

        Schema::create('currency_fx_revaluation_lines', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('revaluation_id')->constrained('currency_fx_revaluations')->cascadeOnDelete();
            $table->string('line_type', 12);
            $table->string('currency', 3);
            $table->string('reference', 120)->nullable();
            $table->uuid('account_id')->nullable();
            $table->decimal('currency_balance', 20, 4);
            $table->decimal('book_value', 18, 2);
            $table->decimal('closing_rate', 18, 6);
            $table->decimal('revalued_value', 18, 2);
            $table->decimal('difference', 18, 2);
            $table->string('result', 8);
            $table->string('treatment', 12)->default('recognise');
            $table->timestamps();
        });
    }

    private function realizedDifferences(): void
    {
        if (Schema::hasTable('currency_realized_fx_differences')) {
            return;
        }

        Schema::create('currency_realized_fx_differences', function (Blueprint $table): void {
            $this->base($table);
            $table->string('allocation_id', 64);
            $table->string('direction', 12);
            $table->string('currency', 3);
            $table->decimal('settled_amount', 20, 4);
            $table->decimal('document_rate', 18, 6);
            $table->decimal('payment_rate', 18, 6)->nullable();
            $table->decimal('document_value', 18, 2);
            $table->decimal('payment_value', 18, 2);
            $table->decimal('difference', 18, 2);
            $table->decimal('effect', 18, 2);
            $table->string('result', 8);
            $table->date('payment_date');
            $table->string('document_reference', 120)->nullable();
            $table->uuid('partner_id')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->boolean('ledger_posted')->default(false);
            $this->audit($table);

            $table->unique(['tenant_id', 'allocation_id']);
            $table->index(['tenant_id', 'payment_date']);
        });
    }
};
