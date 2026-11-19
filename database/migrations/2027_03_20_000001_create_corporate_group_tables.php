<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Legal entities of a corporate group, inside one tenant.
        Schema::create('companies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('parent_company_id')->nullable()->index();
            $table->string('code', 30);
            $table->string('legal_name', 255);
            $table->string('trade_name', 255)->nullable();
            $table->string('vat_number', 30)->nullable();
            $table->string('tax_code', 30)->nullable();
            $table->string('country', 2)->default('IT');
            $table->string('currency', 3)->default('EUR');
            $table->string('consolidation_method', 20)->default('full'); // full, proportional, equity
            $table->decimal('ownership_percentage', 5, 2)->default(100);
            $table->boolean('shares_master_data')->default(false); // true: customers/products are shared with the group
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'uq_company_code');
        });

        // Sites: headquarters, branches, plants, warehouses of a company.
        Schema::create('company_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();
            $table->string('code', 30);
            $table->string('name', 255);
            $table->string('site_type', 30)->default('branch'); // headquarters, branch, plant, warehouse, office
            $table->string('address_line', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('province', 10)->nullable();
            $table->string('country', 2)->default('IT');
            $table->uuid('cost_center_id')->nullable();
            $table->uuid('profit_center_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'code'], 'uq_company_site_code');
        });

        // Per-company access: which users may work on which company, with which permissions.
        Schema::create('company_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('user_id')->index();
            $table->string('role', 30)->default('viewer'); // company_admin, accountant, viewer
            $table->json('permissions')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'user_id'], 'uq_company_user');
        });

        // Accounts each company uses to book intercompany operations.
        Schema::create('company_accounting_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->unique();
            $table->uuid('ic_receivable_account_id')->nullable();
            $table->uuid('ic_payable_account_id')->nullable();
            $table->uuid('ic_revenue_account_id')->nullable();
            $table->uuid('ic_expense_account_id')->nullable();
            $table->uuid('vat_payable_account_id')->nullable();
            $table->uuid('vat_receivable_account_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Document numbering (sezionali) owned by each company.
        Schema::create('company_document_sequences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('company_id')->index();
            $table->string('series_code', 20);
            $table->unsignedSmallInteger('year');
            $table->string('prefix', 20);
            $table->unsignedInteger('last_number')->default(0);
            $table->unsignedTinyInteger('padding')->default(5);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'series_code', 'year'], 'uq_company_sequence');
        });

        Schema::create('intercompany_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('seller_company_id')->index();
            $table->uuid('buyer_company_id')->index();
            $table->string('reference', 60);
            $table->string('transaction_type', 20)->default('goods'); // goods, services, recharge
            $table->date('transaction_date')->index();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('net_amount', 15, 4);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('gross_amount', 15, 4);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('posted'); // posted, cancelled
            $table->uuid('seller_journal_entry_id')->nullable();
            $table->uuid('buyer_journal_entry_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Order and invoice of each side of an intercompany transaction.
        Schema::create('intercompany_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('transaction_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('counterparty_company_id');
            $table->string('side', 10); // seller, buyer
            $table->string('document_type', 20); // sales_order, sales_invoice, purchase_order, purchase_invoice
            $table->string('series_code', 20);
            $table->string('document_number', 50);
            $table->date('document_date');
            $table->string('currency', 3)->default('EUR');
            $table->decimal('net_amount', 15, 4);
            $table->decimal('tax_amount', 15, 4)->default(0);
            $table->decimal('gross_amount', 15, 4);
            $table->json('lines')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->string('status', 20)->default('issued'); // issued, cancelled
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'document_type', 'document_number'], 'uq_ic_document_number');
        });

        Schema::create('consolidation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 255);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('group_currency', 3)->default('EUR');
            $table->json('company_ids');
            $table->json('exchange_rates')->nullable();
            $table->json('summary')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('consolidation_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('run_id')->index();
            $table->string('account_code', 50);
            $table->string('account_name', 255);
            $table->string('account_type', 40);
            $table->string('normal_balance', 10)->default('debit');
            $table->decimal('aggregated_debit', 18, 4)->default(0);
            $table->decimal('aggregated_credit', 18, 4)->default(0);
            $table->decimal('elimination_debit', 18, 4)->default(0);
            $table->decimal('elimination_credit', 18, 4)->default(0);
            $table->decimal('consolidated_debit', 18, 4)->default(0);
            $table->decimal('consolidated_credit', 18, 4)->default(0);
            $table->json('company_breakdown')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('run_id')->references('id')->on('consolidation_runs')->cascadeOnDelete();
        });

        Schema::create('consolidation_eliminations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('run_id')->index();
            $table->uuid('intercompany_transaction_id')->nullable()->index();
            $table->string('elimination_type', 30); // receivable_payable, revenue_expense
            $table->string('debit_account_code', 50);
            $table->string('credit_account_code', 50);
            $table->decimal('amount', 18, 4);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('run_id')->references('id')->on('consolidation_runs')->cascadeOnDelete();
        });

        // Each company owns its chart of accounts: the same account code can exist in two companies of the group.
        if (Schema::hasTable('chart_of_accounts') && Schema::hasColumn('chart_of_accounts', 'company_id') && Schema::hasIndex('chart_of_accounts', ['tenant_id', 'code'], 'unique')) {
            Schema::table('chart_of_accounts', function (Blueprint $table): void {
                $table->dropUnique(['tenant_id', 'code']);
                $table->unique(['tenant_id', 'company_id', 'code'], 'uq_chart_company_code');
            });
        }

        // Cost centres and profit centres can be tied to a site.
        foreach (['cost_centers', 'profit_centers'] as $glTable) {
            if (Schema::hasTable($glTable) && ! Schema::hasColumn($glTable, 'site_id')) {
                Schema::table($glTable, function (Blueprint $table): void {
                    $table->uuid('site_id')->nullable()->index();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('chart_of_accounts') && Schema::hasIndex('chart_of_accounts', 'uq_chart_company_code')) {
            Schema::table('chart_of_accounts', function (Blueprint $table): void {
                $table->dropUnique('uq_chart_company_code');
                $table->unique(['tenant_id', 'code']);
            });
        }

        foreach (['cost_centers', 'profit_centers'] as $glTable) {
            if (Schema::hasTable($glTable) && Schema::hasColumn($glTable, 'site_id')) {
                Schema::table($glTable, function (Blueprint $table): void {
                    $table->dropColumn('site_id');
                });
            }
        }

        foreach ([
            'consolidation_eliminations', 'consolidation_lines', 'consolidation_runs', 'intercompany_documents',
            'intercompany_transactions', 'company_document_sequences', 'company_accounting_settings',
            'company_user', 'company_sites', 'companies',
        ] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
