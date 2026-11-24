<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('riba_presentations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('payment_batch_id')->unique();
            $table->string('sia_code', 5);
            $table->string('file_name', 60)->nullable();
            $table->text('flat_file')->nullable();
            $table->string('file_sha256', 64)->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->timestamps();
        });

        Schema::create('riba_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('riba_presentation_id')->constrained('riba_presentations')->cascadeOnDelete();
            $table->uuid('payment_batch_id')->index();
            $table->uuid('payment_line_id')->index();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->uuid('payment_id')->nullable();
            $table->integer('receipt_number');
            $table->date('due_date');
            $table->decimal('amount', 15, 2);
            $table->string('debtor_name', 255);
            $table->string('debtor_tax_code', 16);
            $table->string('debtor_address', 255);
            $table->string('debtor_postal_code', 5);
            $table->string('debtor_city', 100);
            $table->string('debtor_abi', 5);
            $table->string('debtor_cab', 5);
            $table->string('status', 20)->default('pending');
            $table->date('unpaid_at')->nullable();
            $table->string('unpaid_reason', 255)->nullable();
            $table->decimal('unpaid_fee', 15, 2)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('riba_receipts');
        Schema::dropIfExists('riba_presentations');
    }
};
