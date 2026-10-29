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
        Schema::create('service_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('report_number', 50);
            $table->foreignUuid('status_id')->constrained('lookup_items')->restrictOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->uuid('project_id')->nullable()->index();
            $table->foreignUuid('technician_id')->constrained('users')->restrictOnDelete();
            $table->date('report_date');
            $table->time('time_start')->nullable();
            $table->time('time_end')->nullable();
            $table->decimal('total_hours', 8, 2)->default(0);
            $table->decimal('travel_distance_km', 8, 2)->default(0);
            $table->string('site_address', 255)->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->decimal('total_net', 14, 4)->default(0);
            $table->decimal('total_tax', 14, 4)->default(0);
            $table->decimal('total_gross', 14, 4)->default(0);
            $table->boolean('is_portal_visible')->default(true);
            $table->timestampTz('signed_at')->nullable();
            $table->timestampTz('invoiced_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'report_number']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'technician_id']);
            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'report_date']);
            $table->index(['tenant_id', 'status_id']);
        });

        Schema::create('service_report_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('service_report_id')->constrained('service_reports')->cascadeOnDelete();
            $table->foreignUuid('line_type_id')->constrained('lookup_items')->restrictOnDelete();
            $table->foreignUuid('item_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 4)->default(1);
            $table->string('unit_of_measure', 20)->default('hours');
            $table->decimal('unit_price', 14, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->foreignUuid('vat_code_id')->nullable()->constrained('vat_codes')->nullOnDelete();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('line_net', 14, 4)->default(0);
            $table->decimal('line_tax', 14, 4)->default(0);
            $table->decimal('line_total', 14, 4)->default(0);
            $table->boolean('is_billable')->default(true);
            $table->text('notes')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'service_report_id']);
            $table->index(['tenant_id', 'line_type_id']);
            $table->index(['tenant_id', 'item_id']);
        });

        Schema::create('service_report_signatures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('service_report_id')->constrained('service_reports')->cascadeOnDelete();
            $table->string('signer_type', 30)->default('customer');
            $table->string('signer_name', 150);
            $table->string('signer_role', 100)->nullable();
            $table->string('signer_email', 150)->nullable();
            $table->string('signature_path', 255);
            $table->string('signature_hash', 64);
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->timestampTz('signed_at');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'service_report_id']);
        });

        Schema::create('invoice_service_reports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignUuid('service_report_id')->constrained('service_reports')->restrictOnDelete();
            $table->boolean('is_consolidated')->default(false);
            $table->decimal('invoiced_net_amount', 14, 4)->default(0);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'service_report_id']);
            $table->index(['tenant_id', 'invoice_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_service_reports');
        Schema::dropIfExists('service_report_signatures');
        Schema::dropIfExists('service_report_lines');
        Schema::dropIfExists('service_reports');
    }
};
