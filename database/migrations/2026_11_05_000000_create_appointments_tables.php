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
        Schema::create('appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('appointment_number', 50);
            $table->foreignUuid('status_id')->constrained('lookup_items')->restrictOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained('tickets')->nullOnDelete();
            $table->foreignUuid('service_report_id')->nullable()->constrained('service_reports')->nullOnDelete();
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->boolean('all_day')->default(false);
            $table->string('address', 255)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->integer('estimated_travel_minutes')->default(0);
            $table->integer('estimated_duration_minutes')->default(60);
            $table->text('technician_notes')->nullable();
            $table->text('customer_notes')->nullable();
            $table->boolean('is_portal_visible')->default(true);
            $table->timestampTz('reminder_sent_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['tenant_id', 'appointment_number']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'service_report_id']);
            $table->index(['tenant_id', 'status_id']);
            $table->index(['tenant_id', 'is_portal_visible']);
        });

        Schema::create('appointment_attendees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30)->default('lead'); // lead, technician, assistant
            $table->boolean('is_organizer')->default(false);
            $table->string('status', 30)->default('accepted'); // accepted, pending, declined
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['appointment_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'appointment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_attendees');
        Schema::dropIfExists('appointments');
    }
};
