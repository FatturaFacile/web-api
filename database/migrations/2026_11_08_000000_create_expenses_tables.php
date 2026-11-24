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
        Schema::create('expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('expense_number', 50)->index();
            $table->foreignUuid('category_id')->constrained('lookup_items')->restrictOnDelete();
            $table->date('date')->index();
            $table->decimal('amount_net', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('amount_gross', 15, 2)->default(0);
            $table->string('currency', 3)->default('EUR');
            $table->string('merchant_name', 255)->nullable()->index();
            $table->string('payment_method', 50)->default('cash')->index();
            $table->string('status', 30)->default('submitted')->index();
            $table->boolean('is_billable')->default(false);
            $table->boolean('is_reimbursable')->default(false);
            $table->text('notes')->nullable();
            $table->timestamp('accounting_exported_at')->nullable()->index();

            // Optional entity links
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('project_id')->nullable()->index();
            $table->foreignUuid('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignUuid('service_report_id')->nullable()->constrained('service_reports')->nullOnDelete();

            // Audit metadata
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // PostgreSQL multi-tenant composite performance indexes
            $table->unique(['tenant_id', 'expense_number']);
            $table->index(['tenant_id', 'date']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'category_id']);
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'service_report_id']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'accounting_exported_at']);
        });

        Schema::create('expense_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->foreignUuid('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->string('disk', 50)->default('local');
            $table->string('path', 1000);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->boolean('is_receipt')->default(true);
            $table->text('ocr_raw_text')->nullable();

            // Audit metadata
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['tenant_id', 'expense_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expense_attachments');
        Schema::dropIfExists('expenses');
    }
};
