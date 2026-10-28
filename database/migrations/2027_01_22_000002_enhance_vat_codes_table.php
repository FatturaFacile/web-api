<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vat_codes')) {
            Schema::table('vat_codes', function (Blueprint $table): void {
                if (! Schema::hasColumn('vat_codes', 'tenant_id')) {
                    $table->uuid('tenant_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vat_codes', 'company_id')) {
                    $table->uuid('company_id')->nullable()->index();
                }
                if (! Schema::hasColumn('vat_codes', 'description')) {
                    $table->string('description', 255)->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'tax_rate_percent')) {
                    $table->decimal('tax_rate_percent', 5, 2)->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'country')) {
                    $table->string('country', 3)->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'is_non_deductible')) {
                    $table->boolean('is_non_deductible')->default(false);
                }
                if (! Schema::hasColumn('vat_codes', 'non_deductible_percentage')) {
                    $table->decimal('non_deductible_percentage', 5, 2)->default(0.00);
                }
                if (! Schema::hasColumn('vat_codes', 'gl_sales_tax_account_id')) {
                    $table->uuid('gl_sales_tax_account_id')->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'gl_purchase_tax_account_id')) {
                    $table->uuid('gl_purchase_tax_account_id')->nullable();
                }
                if (! Schema::hasColumn('vat_codes', 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }
    }

    public function down(): void {}
};
