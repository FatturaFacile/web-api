<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chart_of_accounts')) {
            Schema::table('chart_of_accounts', function (Blueprint $table): void {
                if (Schema::hasColumn('chart_of_accounts', 'code')) {
                    $table->string('code', 50)->nullable()->change();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'parent_account_id')) {
                    $table->uuid('parent_account_id')->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'account_code')) {
                    $table->string('account_code', 50)->nullable()->index();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'account_class')) {
                    $table->string('account_class', 30)->default('standard');
                }
                if (! Schema::hasColumn('chart_of_accounts', 'account_type')) {
                    $table->string('account_type', 40)->default('asset');
                }
                if (! Schema::hasColumn('chart_of_accounts', 'normal_balance')) {
                    $table->string('normal_balance', 10)->default('debit');
                }
                if (! Schema::hasColumn('chart_of_accounts', 'currency')) {
                    $table->string('currency', 3)->default('EUR');
                }
                if (! Schema::hasColumn('chart_of_accounts', 'is_reconciliation_account')) {
                    $table->boolean('is_reconciliation_account')->default(false);
                }
                if (! Schema::hasColumn('chart_of_accounts', 'reconciliation_type')) {
                    $table->string('reconciliation_type', 20)->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'allows_manual_posting')) {
                    $table->boolean('allows_manual_posting')->default(true);
                }
                if (! Schema::hasColumn('chart_of_accounts', 'is_cost_center_mandatory')) {
                    $table->boolean('is_cost_center_mandatory')->default(false);
                }
            });

            // Populate account_code from code if null
            if (Schema::hasColumn('chart_of_accounts', 'code') && Schema::hasColumn('chart_of_accounts', 'account_code')) {
                DB::table('chart_of_accounts')->whereNull('account_code')->update(['account_code' => DB::raw('code')]);
            }
        }

        if (Schema::hasTable('vat_codes')) {
            Schema::table('vat_codes', function (Blueprint $table): void {
                if (! Schema::hasColumn('vat_codes', 'tenant_id')) {
                    $table->uuid('tenant_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vat_codes', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vat_codes', 'description')) {
                    $table->string('description', 255)->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'tax_rate_percent')) {
                    $table->decimal('tax_rate_percent', 5, 2)->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'country')) {
                    $table->string('country', 3)->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'is_non_deductible')) {
                    $table->boolean('is_non_deductible')->default(false);
                }
                if (! Schema::hasColumn('vat_codes', 'non_deductible_percentage')) {
                    $table->decimal('non_deductible_percentage', 5, 2)->default(0.00);
                }
                if (! Schema::hasColumn('vat_codes', 'gl_sales_tax_account_id')) {
                    $table->uuid('gl_sales_tax_account_id')->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'gl_purchase_tax_account_id')) {
                    $table->uuid('gl_purchase_tax_account_id')->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        if (Schema::hasTable('vat_settlements')) {
            Schema::table('vat_settlements', function (Blueprint $table): void {
                if (! Schema::hasColumn('vat_settlements', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vat_settlements', 'fiscal_period_id')) {
                    $table->uuid('fiscal_period_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vat_settlements', 'settlement_period')) {
                    $table->string('settlement_period', 20)->nullable();
                }
                if (! Schema::hasColumn('vat_settlements', 'output_vat_amount')) {
                    $table->decimal('output_vat_amount', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'input_vat_amount')) {
                    $table->decimal('input_vat_amount', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'prior_credit_used')) {
                    $table->decimal('prior_credit_used', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'net_vat_payable')) {
                    $table->decimal('net_vat_payable', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'net_vat_credit')) {
                    $table->decimal('net_vat_credit', 15, 4)->default(0.0000);
                }
                if (! Schema::hasColumn('vat_settlements', 'status')) {
                    $table->string('status', 30)->default('calculated');
                }
                if (! Schema::hasColumn('vat_settlements', 'settlement_journal_id')) {
                    $table->uuid('settlement_journal_id')->nullable();
                }
                if (! Schema::hasColumn('vat_settlements', 'calculated_by')) {
                    $table->uuid('calculated_by')->nullable();
                }
            });
        }
    }

    public function down(): void {}
};
