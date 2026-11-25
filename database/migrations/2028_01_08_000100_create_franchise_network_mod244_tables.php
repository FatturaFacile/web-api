<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 244 — franchise network governance: stores (franchisee and directly operated), affiliation agreements (L. 129/2004,
 * entry fee deferral, minimum purchases, guarantees, SEPA SDD / LSV+ mandates), royalty and marketing fund formulas, sell-out,
 * monthly billing runs and statements, central catalogue pushes, cross-store loyalty ledger, store audits and anonymised benchmarks.
 *
 * Every row is owned by the franchisor tenant (`tenant_id`); the franchisee keeps its own tenant, books and fiscal identity and is
 * only referenced by `franchisee_tenant_id`. Rows with a null tenant are global catalogues (formula presets, audit templates,
 * benchmark dimensions). Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->stores();
        $this->formulas();
        $this->agreements();
        $this->entryFeeDeferrals();
        $this->purchaseRecords();
        $this->sellOutReports();
        $this->royaltyRuns();
        $this->royaltyStatements();
        $this->loyaltyTransactions();
        $this->catalogPushes();
        $this->catalogPushTargets();
        $this->auditTemplates();
        $this->audits();
        $this->auditActions();
        $this->benchmarkDimensions();
        $this->benchmarkSnapshots();
        $this->benchmarkNetworkStats();
        $this->collectionBatches();
    }

    public function down(): void
    {
        foreach ([
            'frn_collection_batches', 'frn_benchmark_network_stats', 'frn_benchmark_snapshots', 'frn_benchmark_dimensions', 'frn_audit_actions',
            'frn_audits', 'frn_audit_templates', 'frn_catalog_push_targets', 'frn_catalog_pushes', 'frn_loyalty_transactions',
            'frn_royalty_statements', 'frn_royalty_runs', 'frn_sell_out_reports', 'frn_purchase_records', 'frn_entry_fee_deferrals',
            'frn_agreements', 'frn_formulas', 'frn_stores',
        ] as $table) {
            Schema::dropIfExists($table);
        }
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

    private function stores(): void
    {
        if (Schema::hasTable('frn_stores')) {
            return;
        }

        Schema::create('frn_stores', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('franchisee_tenant_id')->nullable()->index()->constrained('tenants')->nullOnDelete();
            $table->uuid('contact_id')->nullable()->index();
            $table->string('code', 30);
            $table->string('name', 160);
            $table->string('store_type', 20)->default('franchisee');
            $table->string('status', 20)->default('active');
            $table->string('country', 2)->default('IT');
            $table->string('city', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('postal_code', 12)->nullable();
            $table->string('size_cluster', 20)->default('medium');
            $table->date('opened_on')->nullable();
            $table->uuid('area_manager_user_id')->nullable()->index();
            $table->string('vat_number', 30)->nullable();
            $table->string('uid_number', 30)->nullable();
            $table->string('link_code', 40)->nullable()->unique();
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
        });
    }

    private function formulas(): void
    {
        if (Schema::hasTable('frn_formulas')) {
            return;
        }

        Schema::create('frn_formulas', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->json('labels');
            $table->json('descriptions')->nullable();
            $table->boolean('is_preset')->default(false);
            $table->json('royalty_tiers');
            $table->json('marketing_tiers');
            $table->decimal('minimum_guarantee', 14, 2)->default(0);
            $table->unsignedSmallInteger('startup_months')->default(0);
            $table->string('startup_mode', 20)->default('none');
            $table->decimal('startup_reduction_percent', 5, 2)->default(0);
            $table->decimal('ramp_start_percent', 5, 2)->default(0);
            $table->boolean('startup_applies_to_marketing')->default(false);
            $table->string('currency', 3)->default('EUR');
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
        });
    }

    private function agreements(): void
    {
        if (Schema::hasTable('frn_agreements')) {
            return;
        }

        Schema::create('frn_agreements', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->uuid('formula_id')->nullable()->index();
            $table->string('agreement_number', 40);
            $table->string('status', 20)->default('draft');
            $table->string('jurisdiction', 2)->default('IT');
            $table->date('disclosure_delivered_on')->nullable();
            $table->date('signed_on')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedSmallInteger('term_months');
            $table->boolean('auto_renew')->default(false);
            $table->unsignedSmallInteger('renewal_notice_days')->default(180);
            $table->timestamp('renewal_reminder_sent_at')->nullable();
            $table->json('exclusivity_zone')->nullable();
            $table->decimal('entry_fee', 14, 2)->default(0);
            $table->unsignedSmallInteger('entry_fee_deferral_months')->default(0);
            $table->decimal('min_purchase_amount', 14, 2)->default(0);
            $table->string('min_purchase_source', 30)->default('central_warehouse');
            $table->json('approved_supplier_ids')->nullable();
            $table->string('guarantee_type', 30)->default('none');
            $table->decimal('guarantee_amount', 14, 2)->default(0);
            $table->string('guarantee_reference', 80)->nullable();
            $table->date('guarantee_expires_on')->nullable();
            $table->string('payment_method', 20)->default('bank_transfer');
            $table->string('mandate_reference', 60)->nullable();
            $table->date('mandate_signed_on')->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->boolean('non_binding_pricing')->default(false);
            $table->text('notes')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'agreement_number']);
            $table->index(['tenant_id', 'status', 'end_date']);
        });
    }

    private function entryFeeDeferrals(): void
    {
        if (Schema::hasTable('frn_entry_fee_deferrals')) {
            return;
        }

        Schema::create('frn_entry_fee_deferrals', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('agreement_id')->index()->constrained('frn_agreements')->cascadeOnDelete();
            $table->string('period', 7);
            $table->decimal('amount', 14, 2);
            $table->timestamp('recognized_at')->nullable();
            $this->audit($table);
            $table->unique(['agreement_id', 'period']);
        });
    }

    private function purchaseRecords(): void
    {
        if (Schema::hasTable('frn_purchase_records')) {
            return;
        }

        Schema::create('frn_purchase_records', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->string('period', 7);
            $table->decimal('purchased_amount', 14, 2)->default(0);
            $table->decimal('required_amount', 14, 2)->default(0);
            $table->boolean('compliant')->default(true);
            $this->audit($table);
            $table->unique(['store_id', 'period']);
        });
    }

    private function sellOutReports(): void
    {
        if (Schema::hasTable('frn_sell_out_reports')) {
            return;
        }

        Schema::create('frn_sell_out_reports', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->string('period', 7);
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('returns', 14, 2)->default(0);
            $table->decimal('discounts', 14, 2)->default(0);
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->unsignedInteger('tickets_count')->default(0);
            $table->unsignedInteger('visitors_count')->default(0);
            $table->decimal('cost_of_goods_sold', 14, 2)->default(0);
            $table->decimal('average_inventory_value', 14, 2)->default(0);
            $table->string('source', 30)->default('pos');
            $table->string('pos_reference', 80)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $this->audit($table);
            $table->unique(['store_id', 'period']);
            $table->index(['tenant_id', 'period']);
        });
    }

    private function royaltyRuns(): void
    {
        if (Schema::hasTable('frn_royalty_runs')) {
            return;
        }

        Schema::create('frn_royalty_runs', function (Blueprint $table): void {
            $this->base($table);
            $table->string('period', 7);
            $table->string('status', 20)->default('calculated');
            $table->unsignedInteger('stores_count')->default(0);
            $table->decimal('total_royalty', 14, 2)->default(0);
            $table->decimal('total_marketing', 14, 2)->default(0);
            $table->decimal('total_minimum_adjustment', 14, 2)->default(0);
            $table->decimal('total_loyalty_settlement', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->timestamp('calculated_at')->nullable();
            $table->timestamp('invoiced_at')->nullable();
            $this->audit($table);
            $table->unique(['tenant_id', 'period']);
        });
    }

    private function royaltyStatements(): void
    {
        if (Schema::hasTable('frn_royalty_statements')) {
            return;
        }

        Schema::create('frn_royalty_statements', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('run_id')->index()->constrained('frn_royalty_runs')->cascadeOnDelete();
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->uuid('agreement_id')->nullable();
            $table->uuid('formula_id')->nullable();
            $table->string('period', 7);
            $table->string('status', 20)->default('calculated');
            $table->string('skip_reason', 60)->nullable();
            $table->decimal('net_sales', 14, 2)->default(0);
            $table->decimal('royalty_gross', 14, 2)->default(0);
            $table->decimal('startup_factor', 8, 6)->default(1);
            $table->decimal('royalty_after_startup', 14, 2)->default(0);
            $table->decimal('minimum_guarantee_applied', 14, 2)->default(0);
            $table->decimal('minimum_adjustment', 14, 2)->default(0);
            $table->decimal('royalty_charged', 14, 2)->default(0);
            $table->decimal('marketing_fund', 14, 2)->default(0);
            $table->decimal('loyalty_settlement', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->json('breakdown')->nullable();
            $table->uuid('invoice_id')->nullable()->index();
            $this->audit($table);
            $table->unique(['run_id', 'store_id']);
            $table->index(['store_id', 'period']);
        });
    }

    private function loyaltyTransactions(): void
    {
        if (Schema::hasTable('frn_loyalty_transactions')) {
            return;
        }

        Schema::create('frn_loyalty_transactions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->uuid('earned_store_id')->nullable()->index();
            $table->string('customer_ref', 64);
            $table->string('type', 10);
            $table->integer('points');
            $table->decimal('value_amount', 14, 2)->default(0);
            $table->timestamp('occurred_at');
            $table->string('period', 7);
            $table->uuid('settled_run_id')->nullable()->index();
            $this->audit($table);
            $table->index(['tenant_id', 'period', 'type']);
        });
    }

    private function catalogPushes(): void
    {
        if (Schema::hasTable('frn_catalog_pushes')) {
            return;
        }

        Schema::create('frn_catalog_pushes', function (Blueprint $table): void {
            $this->base($table);
            $table->string('push_type', 20);
            $table->string('title', 200);
            $table->json('payload');
            $table->string('target_mode', 10)->default('all');
            $table->json('store_ids')->nullable();
            $table->string('status', 20)->default('draft');
            $table->date('effective_from')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $this->audit($table);
            $table->index(['tenant_id', 'status']);
        });
    }

    private function catalogPushTargets(): void
    {
        if (Schema::hasTable('frn_catalog_push_targets')) {
            return;
        }

        Schema::create('frn_catalog_push_targets', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('push_id')->index()->constrained('frn_catalog_pushes')->cascadeOnDelete();
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 120)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('next_retry_at')->nullable();
            $this->audit($table);
            $table->unique(['push_id', 'store_id']);
        });
    }

    private function auditTemplates(): void
    {
        if (Schema::hasTable('frn_audit_templates')) {
            return;
        }

        Schema::create('frn_audit_templates', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->json('labels');
            $table->json('criteria');
            $table->decimal('pass_threshold_percent', 5, 2)->default(80);
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
        });
    }

    private function audits(): void
    {
        if (Schema::hasTable('frn_audits')) {
            return;
        }

        Schema::create('frn_audits', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->foreignUuid('template_id')->index()->constrained('frn_audit_templates');
            $table->uuid('auditor_id')->nullable()->index();
            $table->string('status', 20)->default('draft');
            $table->date('audited_on');
            $table->decimal('score', 8, 2)->default(0);
            $table->decimal('max_score', 8, 2)->default(0);
            $table->decimal('score_percent', 5, 2)->default(0);
            $table->boolean('passed')->nullable();
            $table->text('notes')->nullable();
            $table->json('answers')->nullable();
            $table->json('photos')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $this->audit($table);
            $table->index(['store_id', 'audited_on']);
        });
    }

    private function auditActions(): void
    {
        if (Schema::hasTable('frn_audit_actions')) {
            return;
        }

        Schema::create('frn_audit_actions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('audit_id')->index()->constrained('frn_audits')->cascadeOnDelete();
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->string('criterion_code', 60);
            $table->text('description');
            $table->date('due_date');
            $table->string('status', 20)->default('open');
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_note')->nullable();
            $this->audit($table);
        });
    }

    private function benchmarkDimensions(): void
    {
        if (Schema::hasTable('frn_benchmark_dimensions')) {
            return;
        }

        Schema::create('frn_benchmark_dimensions', function (Blueprint $table): void {
            $this->base($table);
            $table->string('code', 40);
            $table->json('labels');
            $table->json('descriptions')->nullable();
            $table->string('unit', 12)->default('number');
            $table->boolean('higher_is_better')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $this->audit($table);
            $table->unique(['tenant_id', 'code']);
        });
    }

    private function benchmarkSnapshots(): void
    {
        if (Schema::hasTable('frn_benchmark_snapshots')) {
            return;
        }

        Schema::create('frn_benchmark_snapshots', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('store_id')->index()->constrained('frn_stores')->cascadeOnDelete();
            $table->string('period', 7);
            $table->string('cluster', 20);
            $table->json('metrics');
            $this->audit($table);
            $table->unique(['store_id', 'period']);
        });
    }

    private function benchmarkNetworkStats(): void
    {
        if (Schema::hasTable('frn_benchmark_network_stats')) {
            return;
        }

        Schema::create('frn_benchmark_network_stats', function (Blueprint $table): void {
            $this->base($table);
            $table->string('period', 7);
            $table->string('cluster', 20);
            $table->string('metric', 40);
            $table->decimal('average', 16, 4)->nullable();
            $table->decimal('median', 16, 4)->nullable();
            $table->decimal('p75', 16, 4)->nullable();
            $table->unsignedInteger('sample_size')->default(0);
            $this->audit($table);
            $table->unique(['tenant_id', 'period', 'cluster', 'metric'], 'frn_bench_stats_unique');
        });
    }

    private function collectionBatches(): void
    {
        if (Schema::hasTable('frn_collection_batches')) {
            return;
        }

        Schema::create('frn_collection_batches', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('run_id')->index()->constrained('frn_royalty_runs')->cascadeOnDelete();
            $table->string('scheme', 20);
            $table->string('status', 20)->default('ready');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->date('collection_date');
            $table->json('items');
            $this->audit($table);
            $table->unique(['run_id', 'scheme']);
        });
    }
};
