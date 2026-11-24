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
        Schema::create('alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->nullableUuidMorphs('alertable'); // contact or document; null = global alert
            $table->foreignUuid('created_by')->nullable()->index();
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('severity', 20)->default('info'); // info, warning, critical
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('loyalty_cards', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('card_number', 50);
            $table->string('type', 20)->default('loyalty'); // loyalty, voucher, prepaid
            $table->decimal('balance', 15, 2)->default(0); // points (loyalty) or money (voucher/prepaid)
            $table->string('currency', 3)->default('EUR');
            $table->string('status', 20)->default('active'); // active, blocked, expired
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'card_number']);
        });

        Schema::create('loyalty_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('loyalty_card_id')->constrained('loyalty_cards')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('type', 20); // earn, redeem, topup, adjust
            $table->decimal('amount', 15, 2); // signed
            $table->decimal('balance_after', 15, 2);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['loyalty_card_id', 'created_at']);
        });

        Schema::create('payment_reminders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->unsignedTinyInteger('level'); // 1 = gentle, 2 = formal, 3 = final
            $table->string('channel', 20)->default('email');
            $table->string('recipient')->nullable();
            $table->decimal('amount_due', 15, 2);
            $table->string('status', 20)->default('queued'); // queued, sent, failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'level']);
        });

        Schema::create('payment_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->index();
            $table->string('file_name');
            $table->string('format', 20)->default('camt'); // camt.053 / camt.054
            $table->unsignedInteger('total_entries')->default(0);
            $table->unsignedInteger('matched_entries')->default(0);
            $table->unsignedInteger('duplicate_entries')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_import_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('payment_import_id')->constrained('payment_imports')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('entry_hash', 64);
            $table->date('booking_date')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('CHF');
            $table->string('reference', 50)->nullable(); // QR / SCOR reference
            $table->string('remittance_info', 500)->nullable();
            $table->string('debtor_name')->nullable();
            $table->string('status', 20)->default('unmatched'); // matched, unmatched, duplicate
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'entry_hash']); // an entry is never imported twice
            $table->index(['payment_import_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_import_lines');
        Schema::dropIfExists('payment_imports');
        Schema::dropIfExists('payment_reminders');
        Schema::dropIfExists('loyalty_transactions');
        Schema::dropIfExists('loyalty_cards');
        Schema::dropIfExists('alerts');
    }
};
