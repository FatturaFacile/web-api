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
        Schema::create('bank_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('iban', 34)->index();
            $table->string('qr_iban', 34)->nullable()->index();
            $table->string('bic_swift', 11)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('current_balance', 15, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'currency']);
        });

        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->date('booking_date')->index();
            $table->date('value_date')->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->text('description');
            $table->string('reference')->nullable()->index(); // e.g. SCOR reference or EndToEndId
            $table->uuid('matched_invoice_id')->nullable()->index();
            $table->enum('status', ['unmatched', 'matched', 'manual', 'ignored'])->default('unmatched')->index();
            $table->timestamps();

            $table->index(['tenant_id', 'booking_date']);
        });

        Schema::create('payment_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->enum('format', ['sepa_pain_001_it', 'iso_pain_001_ch'])->default('sepa_pain_001_it');
            $table->string('status', 30)->default('draft')->index(); // draft, generated, transmitted, processed, rejected
            $table->string('file_path')->nullable();
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->date('execution_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_batches');
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('bank_accounts');
    }
};
