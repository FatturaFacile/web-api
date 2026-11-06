<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_cost_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('supplier_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('keyword', 100)->nullable();
            $table->uuid('cost_account_id');
            $table->timestamps();

            $table->index(['tenant_id', 'supplier_id']);
        });

        Schema::create('passive_invoice_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('passive_invoice_id')->constrained('passive_invoices')->cascadeOnDelete();
            $table->integer('line_number');
            $table->text('description');
            $table->decimal('quantity', 15, 4)->nullable();
            $table->string('unit_of_measure', 20)->nullable();
            $table->decimal('unit_price', 19, 8);
            $table->decimal('line_total', 15, 2);
            $table->decimal('tax_rate', 5, 2);
            $table->string('vat_nature', 10)->nullable();
            $table->uuid('cost_account_id')->nullable();
            $table->string('cost_account_source', 10)->nullable();
            $table->timestamps();
        });

        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->uuid('cost_account_id')->nullable();
            $table->string('cost_account_source', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropColumn(['cost_account_id', 'cost_account_source']);
        });

        Schema::dropIfExists('passive_invoice_lines');
        Schema::dropIfExists('supplier_cost_rules');
    }
};
