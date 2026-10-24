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
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('legal_name');
            $table->string('fiscal_code', 16)->nullable()->index();
            $table->string('vat_number', 30)->nullable()->index();
            $table->string('uid_number', 20)->nullable()->index(); // Swiss UID CHE-xxx.xxx.xxx
            $table->enum('country', ['IT', 'CH'])->default('IT')->index();
            $table->string('default_currency', 3)->default('EUR');
            $table->string('iban', 34)->nullable();
            $table->string('qr_iban', 34)->nullable();
            $table->string('sdi_code', 7)->nullable(); // IT SDI recipient code (7 chars)
            $table->string('pec_email')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_user', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('member'); // owner, admin, accountant, technician, member
            $table->json('permissions')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id']);
        });

        Schema::create('currencies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 3)->unique(); // EUR, CHF, USD
            $table->string('name', 50);
            $table->string('symbol', 10);
            $table->unsignedSmallInteger('precision')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('exchange_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source', 3)->index();
            $table->string('target', 3)->index();
            $table->decimal('rate', 16, 6);
            $table->date('effective_date')->index();
            $table->timestamps();

            $table->unique(['source', 'target', 'effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('tenant_user');
        Schema::dropIfExists('tenants');
    }
};
