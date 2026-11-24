<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant VAT configuration: ledger accounts of the settlement, filing period and LIPE declarant.
     */
    public function up(): void
    {
        Schema::create('vat_tax_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->unique();
            $table->string('periodicity', 20)->nullable();
            $table->json('output_vat_account_ids')->nullable();
            $table->json('input_vat_account_ids')->nullable();
            $table->uuid('settlement_account_id')->nullable();
            $table->uuid('interest_expense_account_id')->nullable();
            $table->string('declarant_tax_code', 16)->nullable();
            $table->string('declarant_role_code', 2)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('settlement_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
            $table->foreign('interest_expense_account_id')->references('id')->on('chart_of_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_tax_settings');
    }
};
