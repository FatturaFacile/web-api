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
        Schema::create('contacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('type', 30)->default('client')->index();
            $table->string('country', 2)->default('IT')->index();
            $table->string('company_name')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('vat_number', 30)->nullable()->index();
            $table->string('fiscal_code', 16)->nullable()->index();
            $table->string('uid_number', 20)->nullable()->index();
            $table->string('sdi_code', 7)->nullable(); // 7 chars SDI or 0000000
            $table->string('pec')->nullable();
            $table->string('iban', 34)->nullable();
            $table->string('qr_iban', 34)->nullable();
            $table->unsignedInteger('payment_terms_days')->default(30);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'country']);
        });

        Schema::create('contact_addresses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->enum('type', ['billing', 'shipping'])->default('billing');
            $table->string('street');
            $table->string('house_number', 20)->nullable();
            $table->string('postal_code', 20);
            $table->string('city');
            $table->string('state_canton', 50)->nullable(); // Province for IT (e.g. MI, RM) or Canton for CH (e.g. TI, ZH)
            $table->string('country', 2)->default('IT');
            $table->timestamps();

            $table->index(['contact_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_addresses');
        Schema::dropIfExists('contacts');
    }
};
