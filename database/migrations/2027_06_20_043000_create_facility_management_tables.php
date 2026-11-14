<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facility management and workplace: buildings and spaces, room and desk bookings, service requests, recurring
     * inspections and utility meters. New tables only. Allowed values (kinds, statuses) are enforced by the application,
     * so the schema stays portable. Assets and EAM work orders are referenced by id only.
     */
    public function up(): void
    {
        Schema::create('fac_buildings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('city', 100)->nullable();
            $table->string('country', 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code']);
        });

        Schema::create('fac_spaces', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('building_id')->constrained('fac_buildings')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('kind', 20);
            $table->string('code', 40);
            $table->string('name', 150);
            $table->smallInteger('floor_level')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->decimal('area_sqm', 10, 2)->nullable();
            $table->boolean('is_bookable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('amenities')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'building_id', 'kind']);
            $table->index(['tenant_id', 'parent_id']);
            $table->index(['tenant_id', 'is_bookable', 'kind']);
        });

        Schema::table('fac_spaces', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('fac_spaces')->nullOnDelete();
        });

        Schema::create('fac_booking_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('space_kind', 20);
            $table->unsignedSmallInteger('max_bookings_per_user_per_day')->nullable();
            $table->unsignedInteger('max_minutes_per_booking')->nullable();
            $table->unsignedSmallInteger('max_advance_days')->nullable();
            $table->unsignedSmallInteger('checkin_grace_minutes')->default(15);
            $table->boolean('requires_checkin')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'space_kind']);
        });

        Schema::create('fac_booking_series', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('space_id')->constrained('fac_spaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150)->nullable();
            $table->string('recurrence', 10);
            $table->json('weekdays');
            $table->date('start_date');
            $table->date('until_date');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('status', 10)->default('active');
            $table->unsignedSmallInteger('occurrences')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'space_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('fac_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('space_id')->constrained('fac_spaces')->cascadeOnDelete();
            $table->foreignUuid('series_id')->nullable()->constrained('fac_booking_series')->nullOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150)->nullable();
            $table->unsignedSmallInteger('attendees')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 15)->default('booked');
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'space_id', 'starts_at']);
            $table->index(['tenant_id', 'user_id', 'starts_at']);
            $table->index(['tenant_id', 'status', 'starts_at']);
        });

        Schema::create('fac_service_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('category', 20);
            $table->string('priority', 10)->default('normal');
            $table->string('status', 15)->default('new');
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->foreignUuid('building_id')->nullable()->constrained('fac_buildings')->nullOnDelete();
            $table->foreignUuid('space_id')->nullable()->constrained('fac_spaces')->nullOnDelete();
            $table->foreignUuid('requester_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('asset_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->uuid('inspection_id')->nullable();
            $table->timestamp('due_at');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('hold_reason', 255)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'assignee_id', 'status']);
            $table->index(['tenant_id', 'requester_id']);
            $table->index(['tenant_id', 'due_at']);
        });

        Schema::create('fac_request_comments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('service_request_id')->constrained('fac_service_requests')->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'service_request_id']);
        });

        Schema::create('fac_inspection_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->foreignUuid('building_id')->nullable()->constrained('fac_buildings')->nullOnDelete();
            $table->foreignUuid('space_id')->nullable()->constrained('fac_spaces')->nullOnDelete();
            $table->string('frequency', 12);
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->json('checklist');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('next_due_date');
            $table->unsignedSmallInteger('lead_days')->default(0);
            $table->date('last_generated_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active', 'next_due_date']);
        });

        Schema::create('fac_inspections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('template_id')->nullable()->constrained('fac_inspection_templates')->nullOnDelete();
            $table->string('number', 30);
            $table->string('title', 150);
            $table->foreignUuid('building_id')->nullable()->constrained('fac_buildings')->nullOnDelete();
            $table->foreignUuid('space_id')->nullable()->constrained('fac_spaces')->nullOnDelete();
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date');
            $table->string('status', 15)->default('scheduled');
            $table->string('result', 10)->nullable();
            $table->json('checklist');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('service_request_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'template_id', 'due_date']);
            $table->index(['tenant_id', 'status', 'due_date']);
            $table->index(['tenant_id', 'assignee_id', 'status']);
        });

        Schema::create('fac_meters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('building_id')->constrained('fac_buildings')->cascadeOnDelete();
            $table->foreignUuid('space_id')->nullable()->constrained('fac_spaces')->nullOnDelete();
            $table->string('kind', 12);
            $table->string('code', 40);
            $table->string('name', 150);
            $table->string('unit', 10);
            $table->decimal('tariff_per_unit', 14, 6)->nullable();
            $table->string('currency', 3)->default('EUR');
            $table->decimal('rollover_value', 16, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->date('installed_on')->nullable();
            $table->date('last_read_on')->nullable();
            $table->decimal('last_value', 16, 3)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'building_id', 'kind']);
        });

        Schema::create('fac_meter_readings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('meter_id')->constrained('fac_meters')->cascadeOnDelete();
            $table->date('read_on');
            $table->decimal('value', 16, 3);
            $table->decimal('consumption', 16, 3)->default(0);
            $table->decimal('cost', 15, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('reading_type', 12)->default('regular');
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'meter_id', 'read_on']);
            $table->index(['tenant_id', 'read_on']);
        });
    }

    public function down(): void
    {
        foreach (['fac_meter_readings', 'fac_meters', 'fac_inspections', 'fac_inspection_templates', 'fac_request_comments', 'fac_service_requests', 'fac_bookings', 'fac_booking_series', 'fac_booking_rules', 'fac_spaces', 'fac_buildings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
