<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chart_of_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50)->index(); // Account code (e.g. 1000 Cash, 2000 AP)
            $table->string('name');
            $table->enum('type', ['asset', 'liability', 'equity', 'revenue', 'expense'])->index();
            $table->enum('standard', ['it_general_ledger', 'ch_kmu_pme'])->default('it_general_ledger')->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('general_ledger_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('entry_number', 50)->index();
            $table->date('date')->index();
            $table->text('description');
            $table->string('reference_type')->nullable(); // Invoice, Payment, etc.
            $table->uuid('reference_id')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'entry_number']);
            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('general_ledger_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('general_ledger_entry_id')->constrained('general_ledger_entries')->cascadeOnDelete();
            $table->foreignUuid('account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
            $table->unsignedInteger('line_number')->default(1);
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('vat_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('period_type', ['monthly', 'quarterly'])->default('monthly');
            $table->unsignedSmallInteger('year')->index();
            $table->unsignedTinyInteger('period')->index(); // Month 1-12 or Quarter 1-4
            $table->decimal('total_debit_vat', 15, 2)->default(0);
            $table->decimal('total_credit_vat', 15, 2)->default(0);
            $table->decimal('payable_amount', 15, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->date('payment_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'period_type', 'year', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vat_settlements');
        Schema::dropIfExists('general_ledger_lines');
        Schema::dropIfExists('general_ledger_entries');
        Schema::dropIfExists('chart_of_accounts');
    }
};
