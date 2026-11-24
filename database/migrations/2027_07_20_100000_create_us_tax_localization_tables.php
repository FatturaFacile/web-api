<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * United States localization: sales tax jurisdictions with dated rates, the states where the company has nexus, and
     * the data captured for 1099 reporting (recipients and the payments made to them). Allowed values are enforced by the
     * application. The tax identification number of a recipient is stored encrypted. New tables only.
     */
    public function up(): void
    {
        Schema::create('us_tax_jurisdictions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('name', 160);
            $table->string('state', 2);
            $table->string('level', 20);
            $table->string('county', 80)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('zip_prefix', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'state']);
        });

        Schema::create('us_tax_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('jurisdiction_id')->constrained('us_tax_jurisdictions')->cascadeOnDelete();
            $table->decimal('rate_percent', 7, 4);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('applies_to_shipping')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'jurisdiction_id', 'effective_from']);
        });

        Schema::create('us_tax_nexus', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('state', 2);
            $table->string('nexus_type', 20);
            $table->date('established_on');
            $table->date('ended_on')->nullable();
            $table->string('registration_number', 60)->nullable();
            $table->decimal('sales_threshold', 15, 2)->nullable();
            $table->unsignedInteger('transactions_threshold')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'state']);
        });

        Schema::create('us_1099_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('contact_id')->nullable();
            $table->string('name', 190);
            $table->string('business_name', 190)->nullable();
            $table->string('tin_type', 3)->default('ein');
            $table->text('tin')->nullable();
            $table->string('tin_last4', 4)->nullable();
            $table->string('address_line', 190)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('state', 2)->nullable();
            $table->string('zip', 10)->nullable();
            $table->string('default_form', 12)->default('1099-NEC');
            $table->boolean('is_exempt')->default(false);
            $table->date('w9_received_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('us_1099_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('recipient_id')->constrained('us_1099_recipients')->cascadeOnDelete();
            $table->date('paid_on');
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('form_type', 12);
            $table->string('box', 10);
            $table->string('payment_method', 20)->nullable();
            $table->string('reference', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'recipient_id', 'paid_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('us_1099_payments');
        Schema::dropIfExists('us_1099_recipients');
        Schema::dropIfExists('us_tax_nexus');
        Schema::dropIfExists('us_tax_rates');
        Schema::dropIfExists('us_tax_jurisdictions');
    }
};
