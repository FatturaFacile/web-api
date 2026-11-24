<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Event management: events with their sessions and ticket types, and the registrations with the QR token used at
     * check-in. Capacity, waiting list and promotion rules live in the application, not in the engine.
     */
    public function up(): void
    {
        if (! Schema::hasTable('evt_events')) {
            Schema::create('evt_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->string('venue', 200)->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('status', 12)->default('draft');
                $table->unsignedInteger('capacity')->nullable();
                $table->boolean('waitlist_enabled')->default(true);
                $table->string('currency', 3)->default('EUR');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'starts_at']);
                $table->index(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('evt_sessions')) {
            Schema::create('evt_sessions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('event_id')->constrained('evt_events')->cascadeOnDelete();
                $table->string('title', 160);
                $table->string('speaker', 160)->nullable();
                $table->string('room', 80)->nullable();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['event_id', 'starts_at']);
            });
        }

        if (! Schema::hasTable('evt_ticket_types')) {
            Schema::create('evt_ticket_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('event_id')->constrained('evt_events')->cascadeOnDelete();
                $table->string('name', 120);
                $table->decimal('price', 12, 2)->default(0);
                $table->unsignedInteger('quantity')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['event_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('evt_registrations')) {
            Schema::create('evt_registrations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('event_id')->constrained('evt_events')->cascadeOnDelete();
                $table->foreignUuid('ticket_type_id')->constrained('evt_ticket_types')->cascadeOnDelete();
                $table->uuid('contact_id')->nullable()->index();
                $table->string('attendee_name', 160);
                $table->string('attendee_email', 190)->nullable();
                $table->string('status', 12)->default('confirmed');
                $table->string('qr_token', 64);
                $table->decimal('price', 12, 2)->default(0);
                $table->dateTime('registered_at');
                $table->dateTime('paid_at')->nullable();
                $table->dateTime('checked_in_at')->nullable();
                $table->uuid('checked_in_by')->nullable();
                $table->dateTime('cancelled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique('qr_token');
                $table->index(['event_id', 'status']);
                $table->index(['tenant_id', 'attendee_email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evt_registrations');
        Schema::dropIfExists('evt_ticket_types');
        Schema::dropIfExists('evt_sessions');
        Schema::dropIfExists('evt_events');
    }
};
