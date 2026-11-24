<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commercial_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('pos_sale_id')->nullable()->constrained('pos_sales')->nullOnDelete();
            $table->uuid('related_document_id')->nullable();
            $table->string('document_type', 20);
            $table->integer('fiscal_year');
            $table->integer('sequential_number');
            $table->string('document_number', 40);
            $table->date('issue_date');
            $table->string('payment_method', 30)->nullable();
            $table->decimal('total_net', 15, 2);
            $table->decimal('total_tax', 15, 2);
            $table->decimal('total_gross', 15, 2);
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_status', 20)->default('pending');
            $table->string('gateway_reference', 100)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'fiscal_year', 'sequential_number']);
            $table->index(['tenant_id', 'issue_date']);
        });

        Schema::create('commercial_document_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('commercial_document_id')->constrained('commercial_documents')->cascadeOnDelete();
            $table->uuid('source_line_id')->nullable();
            $table->string('description', 255);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('tax_rate', 5, 2);
            $table->string('vat_nature', 10)->nullable();
            $table->decimal('net_amount', 15, 2);
            $table->decimal('tax_amount', 15, 2);
            $table->decimal('gross_amount', 15, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'commercial_document_id']);
        });

        // A self reference needs the primary key of the table, so it can only be added once the table exists.
        Schema::table('commercial_documents', function (Blueprint $table): void {
            $table->foreign('related_document_id')->references('id')->on('commercial_documents')->nullOnDelete();
        });

        Schema::table('commercial_document_lines', function (Blueprint $table): void {
            $table->foreign('source_line_id')->references('id')->on('commercial_document_lines')->nullOnDelete();
        });

        Schema::create('daily_corrispettivi', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('business_date');
            $table->decimal('tax_rate', 5, 2);
            $table->string('vat_nature', 10)->default('');
            $table->decimal('sales_net', 15, 2);
            $table->decimal('sales_tax', 15, 2);
            $table->decimal('sales_gross', 15, 2);
            $table->decimal('voided_gross', 15, 2);
            $table->decimal('returned_gross', 15, 2);
            $table->decimal('total_net', 15, 2);
            $table->decimal('total_tax', 15, 2);
            $table->decimal('total_gross', 15, 2);
            $table->integer('document_count');
            $table->string('gateway', 30);
            $table->string('gateway_status', 20);
            $table->string('gateway_reference', 100)->nullable();
            $table->timestamp('closed_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'business_date', 'tax_rate', 'vat_nature'], 'daily_corrispettivi_rate_unique');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->decimal('document_quota_points', 12, 1)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('document_quota_points');
        });

        Schema::dropIfExists('daily_corrispettivi');
        Schema::dropIfExists('commercial_document_lines');
        Schema::dropIfExists('commercial_documents');
    }
};
