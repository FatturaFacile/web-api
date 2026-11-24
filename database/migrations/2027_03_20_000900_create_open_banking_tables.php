<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_banking_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('driver', 60);
            $table->string('iban', 34);
            $table->string('external_consent_id', 190);
            $table->text('access_token')->nullable();
            $table->string('status', 20)->index();
            $table->timestamp('expires_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('open_banking_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('consent_id')->index();
            $table->uuid('passive_invoice_id')->nullable()->index();
            $table->string('debtor_iban', 34);
            $table->string('creditor_iban', 34);
            $table->string('creditor_name', 140);
            $table->decimal('amount', 18, 2);
            $table->string('currency', 3);
            $table->string('remittance_information', 140)->nullable();
            $table->string('external_payment_id', 190);
            $table->string('status', 30)->index();
            $table->uuid('initiated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('consent_id')->references('id')->on('open_banking_consents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_banking_payments');
        Schema::dropIfExists('open_banking_consents');
    }
};
