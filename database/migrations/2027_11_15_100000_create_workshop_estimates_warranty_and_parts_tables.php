<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Workshop estimates (with their lines), warranty claims against a folder and the parts a folder consumes from stock.
     */
    public function up(): void
    {
        Schema::create('workshop_estimates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('estimate_number', 40);
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->restrictOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('kind', 20)->default('mechanics');
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3)->default('CHF');
            $table->date('valid_until')->nullable();
            $table->unsignedInteger('mileage_in')->nullable();
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->foreignUuid('folder_id')->nullable()->constrained('workshop_folders')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'estimate_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('workshop_estimate_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('estimate_id')->constrained('workshop_estimates')->cascadeOnDelete();
            $table->string('line_type', 20);
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('quantity', 15, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_percentage', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2);
            $table->decimal('line_total', 15, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'estimate_id']);
        });

        Schema::create('workshop_warranty_claims', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('claim_number', 40);
            $table->foreignUuid('folder_id')->constrained('workshop_folders')->restrictOnDelete();
            $table->foreignUuid('folder_line_id')->nullable()->constrained('workshop_folder_lines')->nullOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignUuid('supplier_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('status', 20)->default('draft');
            $table->string('currency', 3)->default('CHF');
            $table->text('description');
            $table->decimal('claimed_amount', 15, 2)->default(0);
            $table->decimal('reimbursed_amount', 15, 2)->nullable();
            $table->string('supplier_reference', 100)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->date('reimbursed_on')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'claim_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'folder_id']);
        });

        Schema::create('workshop_part_consumptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('folder_id')->constrained('workshop_folders')->cascadeOnDelete();
            $table->foreignUuid('folder_line_id')->nullable()->constrained('workshop_folder_lines')->nullOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('quantity', 15, 4);
            $table->string('status', 20)->default('reserved');
            $table->uuid('stock_reservation_id')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'folder_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_part_consumptions');
        Schema::dropIfExists('workshop_warranty_claims');
        Schema::dropIfExists('workshop_estimate_lines');
        Schema::dropIfExists('workshop_estimates');
    }
};
