<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Courses and events, their calendar and participants, tickets and the bookings of the shared resources. Overlapping
     * bookings of a resource are refused by an exclusion constraint, which also holds between concurrent requests.
     */
    public function up(): void
    {
        Schema::create('assoc_disciplines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('assoc_departments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('assoc_resources', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('assoc_departments')->nullOnDelete();
            $table->string('name');
            $table->string('kind', 30)->default('room');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('assoc_access_areas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('assoc_departments')->nullOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('check_fee')->default(true);
            $table->boolean('check_certificate')->default(false);
            $table->string('required_certificate', 20)->nullable();
            $table->boolean('check_course')->default(false);
            $table->unsignedSmallInteger('course_margin_minutes')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('assoc_courses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20)->default('recurring');
            $table->string('code', 50)->nullable();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->foreignUuid('year_id')->nullable()->constrained('assoc_years')->restrictOnDelete();
            $table->foreignUuid('discipline_id')->nullable()->constrained('assoc_disciplines')->nullOnDelete();
            $table->foreignUuid('department_id')->nullable()->constrained('assoc_departments')->nullOnDelete();
            $table->foreignUuid('resource_id')->nullable()->constrained('assoc_resources')->nullOnDelete();
            $table->foreignUuid('access_area_id')->nullable()->constrained('assoc_access_areas')->nullOnDelete();
            $table->uuid('cost_center_id')->nullable();
            $table->foreignUuid('fee_plan_id')->nullable()->constrained('assoc_fee_plans')->nullOnDelete();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('waitlist_enabled')->default(true);
            $table->boolean('auto_close_when_full')->default(true);
            $table->boolean('registrations_open')->default(true);
            $table->string('required_certificate', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'kind', 'status']);
            $table->index(['tenant_id', 'discipline_id']);
        });

        Schema::create('assoc_course_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('assoc_courses')->cascadeOnDelete();
            $table->string('pattern', 20);
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('on_date')->nullable();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->foreignUuid('resource_id')->nullable()->constrained('assoc_resources')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'course_id']);
        });

        Schema::create('assoc_course_suspensions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('assoc_courses')->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'course_id']);
        });

        Schema::create('assoc_course_instructors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('assoc_courses')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->restrictOnDelete();
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'member_id']);
            $table->index('course_id');
        });

        Schema::create('assoc_course_enrolments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('assoc_courses')->restrictOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->restrictOnDelete();
            $table->string('status', 20)->default('enrolled');
            $table->unsignedInteger('waitlist_position')->nullable();
            $table->date('enrolled_on');
            $table->date('withdrawn_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'course_id', 'status']);
            $table->index('member_id');
        });

        Schema::create('assoc_attendances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('assoc_courses')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->restrictOnDelete();
            $table->date('attended_on');
            $table->string('status', 20)->default('present');
            $table->uuid('fee_assignment_id')->nullable();
            $table->uuid('fee_instalment_id')->nullable();
            $table->uuid('recorded_by_member_id')->nullable();
            $table->uuid('recorded_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['course_id', 'member_id', 'attended_on']);
            $table->index(['tenant_id', 'attended_on']);
        });

        Schema::create('assoc_ticket_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('assoc_courses')->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedInteger('quantity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'course_id']);
        });

        Schema::create('assoc_event_registrations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('assoc_courses')->restrictOnDelete();
            $table->foreignUuid('ticket_type_id')->constrained('assoc_ticket_types')->restrictOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->restrictOnDelete();
            $table->string('status', 20)->default('confirmed');
            $table->unsignedInteger('waitlist_position')->nullable();
            $table->string('ticket_code', 40)->unique();
            $table->uuid('fee_assignment_id')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'course_id', 'status']);
            $table->index('member_id');
        });

        Schema::create('assoc_resource_bookings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('resource_id')->constrained('assoc_resources')->restrictOnDelete();
            $table->foreignUuid('member_id')->nullable()->constrained('assoc_members')->nullOnDelete();
            $table->foreignUuid('course_id')->nullable()->constrained('assoc_courses')->nullOnDelete();
            $table->string('title')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('confirmed');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'starts_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("CREATE UNIQUE INDEX assoc_disciplines_code_unique ON assoc_disciplines (COALESCE(tenant_id::text, ''), code) WHERE deleted_at IS NULL");
            DB::statement('CREATE UNIQUE INDEX assoc_access_areas_code_unique ON assoc_access_areas (tenant_id, code) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_course_instructors_unique ON assoc_course_instructors (course_id, member_id) WHERE deleted_at IS NULL');
            DB::statement("CREATE UNIQUE INDEX assoc_course_enrolments_unique ON assoc_course_enrolments (course_id, member_id) WHERE deleted_at IS NULL AND status <> 'withdrawn'");
            DB::statement("CREATE UNIQUE INDEX assoc_event_registrations_unique ON assoc_event_registrations (ticket_type_id, member_id) WHERE deleted_at IS NULL AND status <> 'cancelled'");
            DB::statement('ALTER TABLE assoc_resource_bookings ADD CONSTRAINT assoc_resource_bookings_range_check CHECK (ends_at > starts_at)');
            DB::statement("ALTER TABLE assoc_resource_bookings ADD CONSTRAINT assoc_resource_bookings_no_overlap EXCLUDE USING gist (resource_id WITH =, tsrange(starts_at, ends_at, '[)') WITH &&) WHERE (status = 'confirmed' AND deleted_at IS NULL)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_resource_bookings');
        Schema::dropIfExists('assoc_event_registrations');
        Schema::dropIfExists('assoc_ticket_types');
        Schema::dropIfExists('assoc_attendances');
        Schema::dropIfExists('assoc_course_enrolments');
        Schema::dropIfExists('assoc_course_instructors');
        Schema::dropIfExists('assoc_course_suspensions');
        Schema::dropIfExists('assoc_course_schedules');
        Schema::dropIfExists('assoc_courses');
        Schema::dropIfExists('assoc_access_areas');
        Schema::dropIfExists('assoc_resources');
        Schema::dropIfExists('assoc_departments');
        Schema::dropIfExists('assoc_disciplines');
    }
};
