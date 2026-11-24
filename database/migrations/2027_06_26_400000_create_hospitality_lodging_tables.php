<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lodging for the hospitality module: room types, physical accommodations, rate plans (seasonal prices), stays with
     * their guest folio, and housekeeping tasks. Availability, overlap and folio rules are enforced by the application.
     * The restaurant rooms (hosp_rooms) are a different thing and are not touched.
     */
    public function up(): void
    {
        if (! Schema::hasTable('hosp_room_types')) {
            Schema::create('hosp_room_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 30);
                $table->string('name', 120);
                $table->unsignedSmallInteger('max_occupancy')->default(2);
                $table->decimal('base_rate', 12, 2)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'code']);
            });
        }

        if (! Schema::hasTable('hosp_accommodations')) {
            Schema::create('hosp_accommodations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('room_type_id')->constrained('hosp_room_types')->cascadeOnDelete();
                $table->string('number', 20);
                $table->string('floor', 20)->nullable();
                $table->string('status', 20)->default('available');
                $table->string('housekeeping_status', 20)->default('clean');
                $table->timestamps();

                $table->unique(['tenant_id', 'number']);
                $table->index(['tenant_id', 'room_type_id']);
            });
        }

        if (! Schema::hasTable('hosp_rate_plans')) {
            Schema::create('hosp_rate_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('room_type_id')->nullable();
                $table->string('code', 30);
                $table->string('name', 120);
                $table->string('meal_plan', 20)->nullable();
                $table->decimal('nightly_rate', 12, 2);
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->unsignedSmallInteger('min_stay')->default(1);
                $table->integer('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'room_type_id']);
            });
        }

        if (! Schema::hasTable('hosp_stays')) {
            Schema::create('hosp_stays', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('number', 30);
                $table->string('guest_name', 160);
                $table->string('guest_email', 190)->nullable();
                $table->uuid('room_type_id');
                $table->uuid('accommodation_id')->nullable();
                $table->uuid('rate_plan_id')->nullable();
                $table->date('check_in_date');
                $table->date('check_out_date');
                $table->unsignedSmallInteger('adults')->default(1);
                $table->string('status', 20)->default('booked');
                $table->decimal('room_total', 12, 2)->default(0);
                $table->json('nightly_rates');
                $table->timestamp('checked_in_at')->nullable();
                $table->timestamp('checked_out_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'number']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'room_type_id', 'check_in_date']);
                $table->index(['tenant_id', 'accommodation_id']);
            });
        }

        if (! Schema::hasTable('hosp_folio_entries')) {
            Schema::create('hosp_folio_entries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('stay_id')->constrained('hosp_stays')->cascadeOnDelete();
                $table->string('entry_type', 10);
                $table->string('category', 20);
                $table->string('description', 190);
                $table->decimal('quantity', 10, 2)->default(1);
                $table->decimal('unit_amount', 12, 2);
                $table->decimal('amount', 12, 2);
                $table->string('payment_method', 20)->nullable();
                $table->date('posted_on');
                $table->timestamp('voided_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'stay_id']);
            });
        }

        if (! Schema::hasTable('hosp_housekeeping_tasks')) {
            Schema::create('hosp_housekeeping_tasks', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('accommodation_id')->constrained('hosp_accommodations')->cascadeOnDelete();
                $table->string('task_type', 20)->default('checkout_clean');
                $table->string('status', 20)->default('pending');
                $table->date('due_on');
                $table->uuid('assigned_to')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['tenant_id', 'status', 'due_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hosp_housekeeping_tasks');
        Schema::dropIfExists('hosp_folio_entries');
        Schema::dropIfExists('hosp_stays');
        Schema::dropIfExists('hosp_rate_plans');
        Schema::dropIfExists('hosp_accommodations');
        Schema::dropIfExists('hosp_room_types');
    }
};
