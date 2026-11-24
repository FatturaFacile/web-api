<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Enhance bank_accounts
        if (Schema::hasTable('bank_accounts')) {
            Schema::table('bank_accounts', function (Blueprint $table): void {
                if (! Schema::hasColumn('bank_accounts', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('bank_accounts', 'branch_name')) {
                    $table->string('branch_name', 150)->nullable();
                }
                if (! Schema::hasColumn('bank_accounts', 'account_number')) {
                    $table->string('account_number', 50)->nullable()->index();
                }
                if (! Schema::hasColumn('bank_accounts', 'account_type')) {
                    $table->string('account_type', 30)->default('checking'); // checking, savings, escrow, credit_line, cash_pool_header
                }
                if (! Schema::hasColumn('bank_accounts', 'gl_account_id')) {
                    $table->uuid('gl_account_id')->nullable()->index();
                }
                if (! Schema::hasColumn('bank_accounts', 'overdraft_limit')) {
                    $table->decimal('overdraft_limit', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('bank_accounts', 'credit_line_limit')) {
                    $table->decimal('credit_line_limit', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('bank_accounts', 'opening_balance')) {
                    $table->decimal('opening_balance', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('bank_accounts', 'available_balance')) {
                    $table->decimal('available_balance', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('bank_accounts', 'is_active')) {
                    $table->boolean('is_active')->default(true);
                }
                if (! Schema::hasColumn('bank_accounts', 'is_pool_header')) {
                    $table->boolean('is_pool_header')->default(false);
                }
            });
        }

        // 2. Enhance payment_batches
        if (Schema::hasTable('payment_batches')) {
            Schema::table('payment_batches', function (Blueprint $table): void {
                if (! Schema::hasColumn('payment_batches', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('payment_batches', 'bank_account_id')) {
                    $table->uuid('bank_account_id')->nullable()->index();
                }
                if (! Schema::hasColumn('payment_batches', 'batch_reference')) {
                    $table->string('batch_reference', 50)->nullable()->index();
                }
                if (! Schema::hasColumn('payment_batches', 'payment_method')) {
                    $table->string('payment_method', 40)->default('sepa_credit_transfer');
                }
                if (! Schema::hasColumn('payment_batches', 'item_count')) {
                    $table->unsignedInteger('item_count')->default(0);
                }
                if (! Schema::hasColumn('payment_batches', 'xml_message_storage_path')) {
                    $table->text('xml_message_storage_path')->nullable();
                }
                if (! Schema::hasColumn('payment_batches', 'xml_sha256')) {
                    $table->string('xml_sha256', 64)->nullable();
                }
                if (! Schema::hasColumn('payment_batches', 'workflow_instance_id')) {
                    $table->uuid('workflow_instance_id')->nullable()->index();
                }
                if (! Schema::hasColumn('payment_batches', 'created_by')) {
                    $table->uuid('created_by')->nullable()->index();
                }
            });
        }

        // 3. Enhance bank_statements
        if (Schema::hasTable('bank_statements')) {
            Schema::table('bank_statements', function (Blueprint $table): void {
                if (! Schema::hasColumn('bank_statements', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('bank_statements', 'bank_account_id')) {
                    $table->uuid('bank_account_id')->nullable()->index();
                }
                if (! Schema::hasColumn('bank_statements', 'statement_number')) {
                    $table->string('statement_number', 50)->nullable()->index();
                }
                if (! Schema::hasColumn('bank_statements', 'format')) {
                    $table->string('format', 20)->default('CAMT_053');
                }
                if (! Schema::hasColumn('bank_statements', 'raw_file_storage_path')) {
                    $table->text('raw_file_storage_path')->nullable();
                }
                if (! Schema::hasColumn('bank_statements', 'file_sha256')) {
                    $table->string('file_sha256', 64)->nullable();
                }
                if (! Schema::hasColumn('bank_statements', 'total_debits')) {
                    $table->decimal('total_debits', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('bank_statements', 'total_credits')) {
                    $table->decimal('total_credits', 15, 2)->default(0.00);
                }
                if (! Schema::hasColumn('bank_statements', 'lines_count')) {
                    $table->unsignedInteger('lines_count')->default(0);
                }
                if (! Schema::hasColumn('bank_statements', 'reconciliation_status')) {
                    $table->string('reconciliation_status', 30)->default('unreconciled');
                }
            });
        }

        // 4. Enhance bank_statement_lines
        if (Schema::hasTable('bank_statement_lines')) {
            Schema::table('bank_statement_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('bank_statement_lines', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'bank_account_id')) {
                    $table->uuid('bank_account_id')->nullable()->index();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'bank_reference')) {
                    $table->string('bank_reference', 100)->nullable()->index();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'customer_reference')) {
                    $table->string('customer_reference', 100)->nullable()->index();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'counterparty_name')) {
                    $table->string('counterparty_name', 255)->nullable();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'counterparty_iban')) {
                    $table->string('counterparty_iban', 34)->nullable()->index();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'counterparty_bic')) {
                    $table->string('counterparty_bic', 11)->nullable();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'remittance_information')) {
                    $table->text('remittance_information')->nullable();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'iso_bank_code')) {
                    $table->string('iso_bank_code', 30)->nullable();
                }
                if (! Schema::hasColumn('bank_statement_lines', 'reconciled_status')) {
                    $table->string('reconciled_status', 30)->default('unmatched');
                }
                if (! Schema::hasColumn('bank_statement_lines', 'reconciled_amount')) {
                    $table->decimal('reconciled_amount', 15, 2)->default(0.00);
                }
            });
        }

        // 5. Enhance bank_transactions
        if (Schema::hasTable('bank_transactions')) {
            Schema::table('bank_transactions', function (Blueprint $table): void {
                if (! Schema::hasColumn('bank_transactions', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('bank_transactions', 'transaction_type')) {
                    $table->string('transaction_type', 30)->default('transfer'); // wire, sepa, card, fee, interest, transfer
                }
                if (! Schema::hasColumn('bank_transactions', 'gl_journal_entry_id')) {
                    $table->uuid('gl_journal_entry_id')->nullable()->index();
                }
            });
        }
    }

    public function down(): void
    {
        // Non-destructive: no dropping of shared columns in enterprise rollbacks
    }
};
