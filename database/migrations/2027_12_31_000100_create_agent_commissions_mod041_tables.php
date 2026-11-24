<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 041 — Sales commissions and agent management (rules by product line / customer / zone, invoiced vs collected
 * accrual with bank-reconciliation lock, Enasarco + FIRR yearly balances, commission statements and liquidation).
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->zones();
        $this->agentProfiles();
        $this->rules();
        $this->fiscalParameters();
        $this->entries();
        $this->balances();
        $this->statements();
    }

    public function down(): void
    {
        foreach (['agc_statement_lines', 'agc_statements', 'agc_agent_year_balances', 'agc_entry_movements', 'agc_entries', 'agc_fiscal_parameters', 'agc_commission_rule_tiers', 'agc_commission_rules', 'agc_agent_profiles', 'agc_zones'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function zones(): void
    {
        if (Schema::hasTable('agc_zones')) {
            return;
        }

        Schema::create('agc_zones', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('country', 2)->nullable();
            $table->json('regions')->nullable(); // province / canton codes; empty = whole country
            $table->boolean('is_active')->default(true);
            $this->audit($table);

            $table->unique(['tenant_id', 'code'], 'agc_zones_code_uq');
        });
    }

    private function agentProfiles(): void
    {
        if (Schema::hasTable('agc_agent_profiles')) {
            return;
        }

        Schema::create('agc_agent_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sales_representative_id')->constrained('sales_representatives')->cascadeOnDelete();
            $table->string('agent_type', 30)->default('direct'); // direct | sub_agent | multi_mandate | single_mandate
            $table->string('fiscal_regime', 30)->default('enasarco'); // enasarco | aec | swiss_direct | none
            $table->string('enasarco_mandate', 10)->nullable(); // single | multi (for direct / sub-agent profiles)
            $table->uuid('parent_agent_profile_id')->nullable();
            $table->string('parent_split_mode', 30)->nullable(); // additional_rate | share_of_commission
            $table->decimal('parent_split_value', 7, 4)->nullable();
            $table->string('accrual_basis', 20)->default('invoiced'); // invoiced | collected
            $table->decimal('default_rate', 7, 4)->nullable();
            $table->string('settlement_channel', 20)->default('invoice'); // invoice | salary
            $table->boolean('has_own_organization')->default(true);
            $table->boolean('vat_applies')->default(true);
            $table->boolean('firr_enabled')->default(true);
            $table->string('enasarco_registration_number', 40)->nullable();
            $table->string('tax_code', 32)->nullable();
            $table->string('vat_number', 32)->nullable();
            $table->date('contract_start')->nullable();
            $table->date('contract_end')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $this->audit($table);

            $table->unique(['tenant_id', 'sales_representative_id'], 'agc_profiles_rep_uq');
            $table->index(['tenant_id', 'parent_agent_profile_id'], 'agc_profiles_parent_idx');
        });
    }

    private function rules(): void
    {
        if (! Schema::hasTable('agc_commission_rules')) {
            Schema::create('agc_commission_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('agent_profile_id')->nullable(); // null = applies to every agent
                $table->string('name', 160);
                $table->uuid('product_category_id')->nullable();
                $table->uuid('customer_id')->nullable();
                $table->uuid('zone_id')->nullable();
                $table->string('rate_type', 20)->default('percentage'); // percentage | fixed
                $table->decimal('rate', 7, 4)->default(0);
                $table->decimal('fixed_amount', 14, 2)->nullable();
                $table->string('accrual_basis', 20)->nullable(); // null = inherit from the agent profile
                $table->unsignedInteger('priority')->default(100); // lower wins
                $table->date('valid_from')->nullable();
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $this->audit($table);

                $table->index(['tenant_id', 'agent_profile_id', 'is_active'], 'agc_rules_agent_idx');
            });
        }

        if (! Schema::hasTable('agc_commission_rule_tiers')) {
            Schema::create('agc_commission_rule_tiers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('rule_id');
                $table->decimal('threshold_from', 14, 2)->default(0);
                $table->decimal('threshold_to', 14, 2)->nullable();
                $table->decimal('rate', 7, 4);
                $this->audit($table);

                $table->index(['tenant_id', 'rule_id'], 'agc_tiers_rule_idx');
            });
        }
    }

    private function fiscalParameters(): void
    {
        if (Schema::hasTable('agc_fiscal_parameters')) {
            return;
        }

        Schema::create('agc_fiscal_parameters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('mandate_type', 10)->nullable(); // single | multi | null = any
            $table->unsignedSmallInteger('year')->nullable(); // null = every year; the latest year <= requested wins
            $table->decimal('value', 16, 6)->nullable();
            $table->json('payload')->nullable();
            $table->string('label_key', 120)->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'code', 'mandate_type', 'year'], 'agc_fiscal_lookup_idx');
        });
    }

    private function entries(): void
    {
        if (! Schema::hasTable('agc_entries')) {
            Schema::create('agc_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('agent_profile_id');
                $table->string('role', 20)->default('primary'); // primary | parent_override
                $table->uuid('invoice_id');
                $table->uuid('invoice_item_id');
                $table->uuid('source_entry_id')->nullable();
                $table->uuid('rule_id')->nullable();
                $table->string('accrual_basis', 20);
                $table->string('currency', 3)->default('EUR');
                $table->date('accrued_on');
                $table->decimal('base_amount', 14, 2);
                $table->decimal('rate', 9, 4)->nullable();
                $table->decimal('commission_amount', 14, 2);
                $table->decimal('credited_amount', 14, 2)->default(0);
                $table->decimal('eligible_amount', 14, 2)->default(0);
                $table->decimal('settled_amount', 14, 2)->default(0);
                $table->decimal('collected_ratio', 9, 6)->default(0);
                $table->decimal('reconciled_ratio', 9, 6)->default(0);
                $table->string('status', 30)->default('pending_collection');
                $this->audit($table);

                $table->unique(['tenant_id', 'invoice_item_id', 'agent_profile_id', 'role'], 'agc_entries_item_uq');
                $table->index(['tenant_id', 'agent_profile_id', 'status'], 'agc_entries_agent_idx');
                $table->index(['tenant_id', 'invoice_id'], 'agc_entries_invoice_idx');
            });
        }

        if (! Schema::hasTable('agc_entry_movements')) {
            Schema::create('agc_entry_movements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('entry_id');
                $table->string('kind', 20); // accrual | release | reversal | credit_note
                $table->decimal('amount', 14, 2);
                $table->date('occurred_on');
                $table->string('note', 255)->nullable();
                $this->audit($table);

                $table->index(['tenant_id', 'entry_id'], 'agc_movements_entry_idx');
            });
        }
    }

    private function balances(): void
    {
        if (Schema::hasTable('agc_agent_year_balances')) {
            return;
        }

        Schema::create('agc_agent_year_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('agent_profile_id');
            $table->unsignedSmallInteger('year');
            $table->decimal('commission_ytd', 14, 2)->default(0);
            $table->decimal('enasarco_base_ytd', 14, 2)->default(0);
            $table->decimal('enasarco_agent_ytd', 14, 2)->default(0);
            $table->decimal('enasarco_company_ytd', 14, 2)->default(0);
            $table->decimal('firr_ytd', 14, 2)->default(0);
            $table->decimal('massimale', 14, 2)->nullable();
            $table->decimal('minimale', 14, 2)->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'agent_profile_id', 'year'], 'agc_balances_uq');
        });
    }

    private function statements(): void
    {
        if (! Schema::hasTable('agc_statements')) {
            Schema::create('agc_statements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('agent_profile_id');
                $table->string('number', 40);
                $table->string('period_type', 20)->default('custom'); // monthly | quarterly | custom
                $table->date('period_start');
                $table->date('period_end');
                $table->string('status', 20)->default('draft'); // draft | approved | invoiced | paid | void
                $table->string('currency', 3)->default('EUR');
                $table->decimal('base_total', 14, 2)->default(0);
                $table->decimal('own_commission_total', 14, 2)->default(0);
                $table->decimal('override_commission_total', 14, 2)->default(0);
                $table->decimal('clawback_total', 14, 2)->default(0);
                $table->decimal('gross_commission', 14, 2)->default(0);
                $table->decimal('enasarco_base', 14, 2)->default(0);
                $table->decimal('enasarco_agent', 14, 2)->default(0);
                $table->decimal('enasarco_company', 14, 2)->default(0);
                $table->decimal('firr_accrual', 14, 2)->default(0);
                $table->decimal('withholding_base', 14, 2)->default(0);
                $table->decimal('withholding_amount', 14, 2)->default(0);
                $table->decimal('vat_amount', 14, 2)->default(0);
                $table->decimal('net_payable', 14, 2)->default(0);
                $table->decimal('blocked_amount', 14, 2)->default(0);
                $table->boolean('massimale_reached')->default(false);
                $table->json('fiscal_snapshot')->nullable();
                $table->uuid('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->string('self_billing_number', 40)->nullable();
                $table->date('self_billing_date')->nullable();
                $table->json('self_billing_payload')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->string('payment_reference', 120)->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->string('sent_to', 190)->nullable();
                $table->unsignedSmallInteger('sent_count')->default(0);
                $this->audit($table);

                $table->unique(['tenant_id', 'number'], 'agc_statements_number_uq');
                $table->index(['tenant_id', 'agent_profile_id', 'period_start'], 'agc_statements_agent_idx');
                $table->index(['tenant_id', 'status'], 'agc_statements_status_idx');
            });
        }

        if (! Schema::hasTable('agc_statement_lines')) {
            Schema::create('agc_statement_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('statement_id');
                $table->uuid('entry_id');
                $table->uuid('invoice_id');
                $table->string('invoice_number', 60)->nullable();
                $table->date('invoice_date')->nullable();
                $table->string('customer_name', 190)->nullable();
                $table->string('role', 20)->default('primary');
                $table->string('accrual_basis', 20);
                $table->decimal('base_amount', 14, 2)->default(0);
                $table->decimal('rate', 9, 4)->nullable();
                $table->decimal('commission_amount', 14, 2);
                $this->audit($table);

                $table->index(['tenant_id', 'statement_id'], 'agc_lines_statement_idx');
                $table->index(['tenant_id', 'entry_id'], 'agc_lines_entry_idx');
            });
        }
    }
};
