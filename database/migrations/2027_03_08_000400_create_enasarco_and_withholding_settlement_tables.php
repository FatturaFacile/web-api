<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New tables only. `enasarco_parameters` starts empty on purpose: rates and ceilings are entered per year with their
     * source. A null tenant is a generic row shown to every tenant and read-only for them.
     */
    public function up(): void
    {
        Schema::create('enasarco_parameters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->string('agent_type', 20);
            $table->decimal('rate_percent', 5, 2);
            $table->decimal('agent_share_percent', 5, 2);
            $table->decimal('annual_ceiling', 15, 2);
            $table->string('source', 255);
            $table->timestamps();

            $table->index(['year', 'agent_type']);
        });

        Schema::create('withholding_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('currency', 3);
            $table->decimal('total_withheld', 15, 2)->default(0);
            $table->decimal('total_f24', 15, 2)->default(0);
            $table->date('due_date');
            $table->string('status', 20)->default('calculated');
            $table->date('payment_date')->nullable();
            $table->uuid('payment_journal_id')->nullable();
            $table->uuid('calculated_by')->nullable();
            $table->uuid('paid_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'year', 'month']);
        });

        Schema::create('withholding_settlement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('withholding_settlement_id')->constrained('withholding_settlements')->cascadeOnDelete();
            $table->uuid('passive_invoice_id')->index();
            $table->uuid('supplier_payment_id');
            $table->uuid('supplier_id')->index();
            $table->string('invoice_number', 50);
            $table->date('paid_at');
            $table->string('withholding_type', 4);
            $table->string('reason', 2)->nullable();
            $table->string('f24_code', 6)->nullable();
            $table->decimal('gross_paid', 15, 2);
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('rate_percent', 5, 2)->nullable();
            $table->decimal('withheld_amount', 15, 2);
            $table->timestamps();

            $table->unique(['supplier_payment_id', 'withholding_type'], 'withholding_lines_payment_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_settlement_lines');
        Schema::dropIfExists('withholding_settlements');
        Schema::dropIfExists('enasarco_parameters');
    }
};
