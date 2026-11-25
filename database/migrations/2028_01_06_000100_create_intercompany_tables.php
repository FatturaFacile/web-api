<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Intercompany accounting and consolidation workflow (Mod 021), on top of the `gl_consolidation_*` tables.
     *
     * Every table belongs to the tenant that owns the consolidation group (`tenant_id`); the entities of the group are
     * referenced by plain uuid columns (`*_tenant_id`) and are never written through these rows. Ids of documents and
     * contacts of other entities are plain uuids as well, so no foreign key can point into the books of another tenant.
     */
    public function up(): void
    {
        $this->settings();
        $this->partyLinks();
        $this->transactionLinks();
        $this->syncEvents();
        $this->netting();
        $this->reconciliation();
        $this->periodRates();
        $this->runWorkflow();
        $this->packages();
        $this->manualAdjustments();
    }

    public function down(): void
    {
        foreach (['ic_manual_adjustments', 'ic_consolidation_packages', 'ic_run_workflow_events', 'ic_run_workflows', 'ic_period_rates', 'ic_reconciliation_items', 'ic_reconciliation_batches', 'ic_netting_items', 'ic_netting_runs', 'ic_sync_events', 'ic_transaction_links', 'ic_party_links', 'ic_group_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function settings(): void
    {
        Schema::create('ic_group_settings', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('group_id');
            $table->string('mirror_mode', 20)->default('draft'); // draft | confirmed
            $table->boolean('auto_mirror')->default(true);
            $table->boolean('sync_payments')->default(true);
            $table->boolean('netting_enabled')->default(true);
            $table->decimal('amount_tolerance', 18, 4)->default(1);
            $table->unsignedSmallInteger('date_tolerance_days')->default(5);
            $table->string('default_standard', 10)->default('fer30'); // fer30 | ifrs10 | oic17
            $this->audit($table);
            $table->unique(['tenant_id', 'group_id'], 'ic_group_settings_group_uq');
        });
    }

    private function partyLinks(): void
    {
        Schema::create('ic_party_links', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('group_id')->index();
            $table->uuid('entity_tenant_id'); // the entity whose books hold the contact
            $table->uuid('counterparty_tenant_id'); // the group entity the contact stands for
            $table->uuid('contact_id');
            $table->string('role', 20); // customer | supplier
            $this->audit($table);
            $table->unique(['group_id', 'entity_tenant_id', 'counterparty_tenant_id', 'role'], 'ic_party_links_uq');
            $table->index(['entity_tenant_id', 'contact_id'], 'ic_party_links_contact_idx');
        });
    }

    private function transactionLinks(): void
    {
        Schema::create('ic_transaction_links', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('group_id');
            $table->uuid('pair_id')->index();
            $table->string('document_type', 20); // invoice | debit_note | credit_note
            $table->uuid('source_tenant_id');
            $table->uuid('source_document_id');
            $table->string('source_document_number', 60)->nullable();
            $table->uuid('mirror_tenant_id');
            $table->uuid('mirror_document_id')->nullable();
            $table->string('mirror_document_number', 60)->nullable();
            $table->string('mirror_status', 20)->nullable(); // draft | confirmed
            $table->string('status', 20)->default('pending'); // pending | drafted | confirmed | reversed | failed | skipped
            $table->string('currency', 3);
            $table->decimal('net_amount', 18, 4)->default(0);
            $table->decimal('tax_amount', 18, 4)->default(0);
            $table->decimal('gross_amount', 18, 4)->default(0);
            $table->date('document_date');
            $table->string('payment_status', 20)->default('unpaid'); // unpaid | partial | paid
            $table->decimal('synced_paid_amount', 18, 4)->default(0);
            $table->decimal('paid_variance', 18, 4)->default(0);
            $table->string('error_message', 500)->nullable();
            $table->string('idempotency_key', 120);
            $table->unsignedInteger('version')->default(1);
            $this->audit($table);
            $table->unique(['tenant_id', 'idempotency_key'], 'ic_tx_links_idem_uq');
            $table->index(['group_id', 'status'], 'ic_tx_links_status_idx');
            $table->index(['source_tenant_id', 'source_document_id'], 'ic_tx_links_source_idx');
            $table->index(['mirror_tenant_id', 'mirror_document_id'], 'ic_tx_links_mirror_idx');
        });
    }

    private function syncEvents(): void
    {
        Schema::create('ic_sync_events', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('group_id')->index();
            $table->uuid('transaction_id')->nullable()->index();
            $table->string('event', 40);
            $table->string('message_key', 120)->nullable();
            $table->json('context')->nullable();
            $table->decimal('amount', 18, 4)->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('user_id')->nullable();
            $this->audit($table);
        });
    }

    private function netting(): void
    {
        Schema::create('ic_netting_runs', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('group_id')->index();
            $table->uuid('entity_a_tenant_id');
            $table->uuid('entity_b_tenant_id');
            $table->date('as_of_date');
            $table->string('currency', 3);
            $table->string('status', 20)->default('draft'); // draft | confirmed | cancelled
            $table->decimal('receivable_total', 18, 4)->default(0); // owed to A by B
            $table->decimal('payable_total', 18, 4)->default(0); // owed to B by A
            $table->decimal('net_amount', 18, 4)->default(0);
            $table->string('net_direction', 20)->default('balanced'); // a_pays_b | b_pays_a | balanced
            $table->decimal('fx_variance', 18, 4)->default(0);
            $table->decimal('amount_variance', 18, 4)->default(0);
            $table->string('cancel_reason', 500)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('confirmed_at')->nullable();
            $this->audit($table);
            $table->index(['group_id', 'status'], 'ic_netting_runs_status_idx');
        });

        Schema::create('ic_netting_items', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('netting_run_id')->index();
            $table->uuid('transaction_id')->nullable();
            $table->string('side', 1); // a | b
            $table->string('document_number', 60)->nullable();
            $table->string('currency', 3);
            $table->decimal('open_amount', 18, 4);
            $table->decimal('applied_amount', 18, 4)->default(0);
            $table->decimal('fx_rate', 18, 8)->default(1);
            $table->decimal('variance', 18, 4)->default(0);
            $this->audit($table);
        });
    }

    private function reconciliation(): void
    {
        Schema::create('ic_reconciliation_batches', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('group_id')->index();
            $table->date('as_of_date');
            $table->string('status', 20)->default('open'); // open | closed
            $table->json('totals')->nullable();
            $this->audit($table);
        });

        Schema::create('ic_reconciliation_items', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('batch_id')->index();
            $table->uuid('group_id');
            $table->uuid('entity_a_tenant_id');
            $table->uuid('entity_b_tenant_id');
            $table->uuid('a_document_id')->nullable();
            $table->string('a_document_number', 60)->nullable();
            $table->date('a_document_date')->nullable();
            $table->string('a_currency', 3)->nullable();
            $table->decimal('a_amount', 18, 4)->nullable();
            $table->decimal('a_tax', 18, 4)->default(0);
            $table->uuid('b_document_id')->nullable();
            $table->string('b_document_number', 60)->nullable();
            $table->date('b_document_date')->nullable();
            $table->string('b_currency', 3)->nullable();
            $table->decimal('b_amount', 18, 4)->nullable();
            $table->decimal('b_tax', 18, 4)->nullable();
            $table->decimal('difference', 18, 4)->default(0);
            $table->decimal('difference_group_currency', 18, 4)->default(0);
            $table->string('status', 20)->default('unmatched'); // unmatched | matched | disputed | written_off | adjusted
            $table->string('anomaly_type', 30)->default('none'); // none | timing | fx | amount | tax | missing_counterpart
            $table->string('anomaly_reason', 120)->nullable();
            $table->string('match_kind', 20)->nullable(); // exact | tolerance | manual
            $table->string('resolution_reason', 500)->nullable();
            $table->decimal('adjustment_amount', 18, 4)->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $this->audit($table);
            $table->index(['batch_id', 'status'], 'ic_recon_items_status_idx');
            $table->index(['batch_id', 'anomaly_type'], 'ic_recon_items_anomaly_idx');
        });
    }

    private function periodRates(): void
    {
        Schema::create('ic_period_rates', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('group_id')->nullable()->index();
            $table->string('group_currency', 3);
            $table->string('currency', 3);
            $table->string('rate_type', 20); // closing | average | historical
            $table->date('period_end');
            $table->decimal('rate', 18, 8); // group-currency units for one unit of `currency`
            $table->string('note', 500)->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'group_currency', 'currency', 'rate_type', 'period_end'], 'ic_period_rates_uq');
        });
    }

    private function runWorkflow(): void
    {
        Schema::create('ic_run_workflows', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('run_id');
            $table->string('status', 20)->default('draft'); // draft | calculating | review | locked | posted
            $table->unsignedInteger('version')->default(1);
            $this->audit($table);
            $table->unique(['tenant_id', 'run_id'], 'ic_run_workflows_run_uq');
        });

        Schema::create('ic_run_workflow_events', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('run_id')->index();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('action', 20); // advance | reopen | lock | post
            $table->string('reason', 500)->nullable();
            $table->uuid('user_id')->nullable();
            $this->audit($table);
        });
    }

    private function manualAdjustments(): void
    {
        Schema::create('ic_manual_adjustments', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('run_id')->index();
            $table->string('reference', 120); // mandatory document reference
            $table->string('reason', 500); // mandatory reason
            $table->string('status', 10)->default('active'); // active | void
            $table->decimal('amount', 18, 4)->default(0);
            $table->json('lines'); // [{entity_tenant_id, account_code, account_name, account_type, debit, credit}]
            $this->audit($table);
        });
    }

    private function packages(): void
    {
        Schema::create('ic_consolidation_packages', function (Blueprint $table): void {
            $this->base($table);
            $table->uuid('run_id')->index();
            $table->string('standard', 10);
            $table->json('payload');
            $table->string('checksum', 64);
            $table->timestamp('generated_at');
            $this->audit($table);
        });
    }
};
