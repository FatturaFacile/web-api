<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Table reservations: guest, party, the table (optional until assigned), the booked interval in UTC and the order
     * opened when the guests are seated.
     */
    public function up(): void
    {
        Schema::create('hosp_reservations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('table_id')->nullable()->constrained('hosp_tables')->restrictOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('hosp_orders')->nullOnDelete();
            $table->string('guest_name', 150);
            $table->string('guest_phone', 40)->nullable();
            $table->string('guest_email', 190)->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 12)->default('booked');
            $table->string('notes', 500)->nullable();
            $table->string('status_reason', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);
            $table->index(['tenant_id', 'table_id', 'starts_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hosp_reservations');
    }
};
