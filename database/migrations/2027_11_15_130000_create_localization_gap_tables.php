<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Localization gaps: the lettering (FEC EcritureLet and DateLet) of ledger lines, kept apart from the immutable posted
     * lines; the US sales tax exemption certificates of customers; the link of a 1099 payment to the supplier payment it
     * was derived from. New tables and one nullable column only.
     */
    public function up(): void
    {
        Schema::create('journal_line_letterings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('journal_line_id');
            $table->uuid('account_id');
            $table->string('lettering_code', 10);
            $table->date('lettered_on');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'lettering_code']);
            $table->index(['tenant_id', 'journal_line_id']);
        });

        Schema::create('us_tax_exemption_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('contact_id');
            $table->uuid('jurisdiction_id')->nullable();
            $table->string('state', 2);
            $table->string('certificate_number', 60);
            $table->string('reason', 30)->default('resale');
            $table->date('issued_on');
            $table->date('expires_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contact_id', 'state']);
        });

        Schema::table('us_1099_payments', function (Blueprint $table): void {
            $table->uuid('supplier_payment_id')->nullable()->after('recipient_id');
            $table->index(['tenant_id', 'supplier_payment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('us_1099_payments', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'supplier_payment_id']);
            $table->dropColumn('supplier_payment_id');
        });
        Schema::dropIfExists('us_tax_exemption_certificates');
        Schema::dropIfExists('journal_line_letterings');
    }
};
