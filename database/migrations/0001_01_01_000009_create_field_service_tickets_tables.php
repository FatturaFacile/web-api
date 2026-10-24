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
        Schema::create('tickets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('subject');
            $table->text('description')->nullable();
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium')->index();
            $table->enum('status', ['open', 'in_progress', 'pending', 'resolved', 'closed'])->default('open')->index();
            $table->foreignUuid('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sla_due_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('work_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('technician_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('work_order_number', 50)->index();
            $table->date('date');
            $table->decimal('labor_hours', 6, 2)->default(0);
            $table->decimal('hourly_rate', 10, 2)->default(0);
            $table->decimal('parts_total', 15, 2)->default(0);
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('client_signature_path')->nullable();
            $table->string('client_signed_by')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->enum('status', ['draft', 'completed', 'signed', 'invoiced'])->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'work_order_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('tickets');
    }
};
