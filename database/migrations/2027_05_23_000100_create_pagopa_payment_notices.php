<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * pagoPA payment notices for receivables and the per-tenant counter the IUV (payment identifier) is generated from.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pagopa_iuv_counters')) {
            Schema::create('pagopa_iuv_counters', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->unique();
                $table->unsignedBigInteger('last_sequence')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pagopa_payment_notices')) {
            Schema::create('pagopa_payment_notices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('invoice_id')->nullable()->index();
                $table->string('reference_type', 100)->nullable();
                $table->uuid('reference_id')->nullable();
                $table->string('debtor_name', 150);
                $table->string('debtor_fiscal_code', 16)->nullable();
                $table->decimal('amount', 15, 2);
                $table->string('currency', 3)->default('EUR');
                $table->date('due_date');
                $table->string('causale', 140);
                $table->string('iuv', 13);
                $table->string('notice_code', 18);
                $table->string('creditor_fiscal_code', 11);
                $table->string('status', 20)->default('pending')->index();
                $table->timestamp('paid_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'iuv'], 'uq_pagopa_notice_iuv');
                $table->index(['reference_type', 'reference_id'], 'idx_pagopa_notice_reference');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pagopa_payment_notices');
        Schema::dropIfExists('pagopa_iuv_counters');
    }
};
