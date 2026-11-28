<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #532 (Mod 286) — ski and snowboard schools, alpine guides and outdoor centres: the instructor roster (languages, disciplines,
 * availability), the private lessons, the weekly group courses by technical level with their enrolments, attendance and level transfers,
 * the bundled package items (rental, ARTVA kit, skipass), the secretariat commissions and the monthly payout statements.
 * Tenant isolation is the application level TenantScope (nullable tenant_id on every row; null rows are global read-only reference data).
 * Dialect-agnostic (SQLite and PostgreSQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->settings();
        $this->instructors();
        $this->levelsAndCourses();
        $this->lessons();
        $this->packageItems();
        $this->payouts();
    }

    public function down(): void
    {
        foreach ([
            'instructor_commissions', 'instructor_payout_statements', 'ski_lesson_package_items', 'group_course_transfers', 'group_course_attendances',
            'group_course_enrollments', 'group_course_classes', 'group_course_levels', 'ski_lessons', 'ski_instructor_availabilities',
            'ski_instructor_disciplines', 'ski_instructor_languages', 'ski_instructors', 'ski_school_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function settings(): void
    {
        if (Schema::hasTable('ski_school_settings')) {
            return;
        }

        Schema::create('ski_school_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('public_slug', 80)->unique();
            $table->boolean('booking_enabled')->default(false);
            $table->string('jurisdiction', 2)->default('IT');
            $table->string('default_retention_mode', 20)->default('PERCENT');
            $table->decimal('default_retention_value', 10, 4)->default(10);
            $table->string('meeting_point', 160)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id'], 'sks_tenant_uq');
        });
    }

    private function instructors(): void
    {
        if (! Schema::hasTable('ski_instructors')) {
            Schema::create('ski_instructors', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('first_name', 80);
                $table->string('last_name', 80);
                $table->string('email', 160)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('license_type', 30);
                $table->string('license_number', 60)->nullable();
                $table->date('license_valid_until')->nullable();
                $table->decimal('hourly_rate', 12, 2)->default(0);
                $table->string('retention_mode', 20)->default('PERCENT');
                $table->decimal('retention_value', 10, 4)->default(0);
                $table->string('collaboration_regime', 30)->default('FREELANCE');
                $table->decimal('withholding_percent', 6, 2)->default(0);
                $table->decimal('max_weekly_hours', 6, 2)->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'is_active'], 'ski_ins_active_idx');
                $table->index(['tenant_id', 'last_name', 'first_name'], 'ski_ins_name_idx');
            });
        }

        if (! Schema::hasTable('ski_instructor_languages')) {
            Schema::create('ski_instructor_languages', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('instructor_id')->constrained('ski_instructors')->cascadeOnDelete();
                $table->string('language_code', 8);
                $table->string('proficiency', 12)->default('FLUENT');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'language_code'], 'ski_insl_lang_idx');
                $table->index('instructor_id', 'ski_insl_ins_idx');
            });
        }

        if (! Schema::hasTable('ski_instructor_disciplines')) {
            Schema::create('ski_instructor_disciplines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('instructor_id')->constrained('ski_instructors')->cascadeOnDelete();
                $table->string('discipline', 30);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'discipline'], 'ski_insd_disc_idx');
                $table->index('instructor_id', 'ski_insd_ins_idx');
            });
        }

        if (! Schema::hasTable('ski_instructor_availabilities')) {
            Schema::create('ski_instructor_availabilities', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('instructor_id')->constrained('ski_instructors')->cascadeOnDelete();
                $table->date('slot_date');
                $table->string('start_time', 5);
                $table->string('end_time', 5);
                $table->string('kind', 12)->default('BLACKOUT');
                $table->string('note', 200)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'instructor_id', 'slot_date'], 'ski_insa_day_idx');
            });
        }
    }

    private function levelsAndCourses(): void
    {
        if (! Schema::hasTable('group_course_levels')) {
            Schema::create('group_course_levels', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('system', 24);
                $table->string('code', 24);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->string('color', 9)->default('#1e88e5');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'system', 'code'], 'gcl_code_uq');
                $table->index(['system', 'sort_order'], 'gcl_sort_idx');
            });
        }

        if (! Schema::hasTable('group_course_classes')) {
            Schema::create('group_course_classes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('discipline', 30);
                $table->foreignUuid('level_id')->constrained('group_course_levels')->restrictOnDelete();
                $table->foreignUuid('instructor_id')->nullable()->constrained('ski_instructors')->nullOnDelete();
                $table->string('language_code', 8);
                $table->date('week_start');
                $table->date('end_date');
                $table->string('daily_start_time', 5);
                $table->unsignedSmallInteger('duration_minutes')->default(120);
                $table->unsignedSmallInteger('capacity')->default(8);
                $table->unsignedSmallInteger('min_participants')->default(3);
                $table->string('meeting_point', 160)->nullable();
                $table->decimal('price_net', 12, 2)->default(0);
                $table->string('vat_regime', 24)->default('IT_EXEMPT_ART10_N20');
                $table->string('status', 16)->default('PLANNED');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code'], 'gcc_code_uq');
                $table->index(['tenant_id', 'week_start', 'status'], 'gcc_week_idx');
                $table->index(['tenant_id', 'instructor_id', 'week_start'], 'gcc_ins_idx');
            });
        }

        if (! Schema::hasTable('group_course_enrollments')) {
            Schema::create('group_course_enrollments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('class_id')->constrained('group_course_classes')->restrictOnDelete();
                $table->foreignUuid('initial_class_id')->nullable()->constrained('group_course_classes')->nullOnDelete();
                $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('student_name', 160);
                $table->date('birth_date')->nullable();
                $table->string('guardian_name', 160)->nullable();
                $table->string('guardian_email', 160)->nullable();
                $table->string('emergency_contact_name', 160)->nullable();
                $table->string('emergency_contact_phone', 40)->nullable();
                $table->unsignedSmallInteger('bib_number')->nullable();
                $table->decimal('price_net', 12, 2)->default(0);
                $table->string('status', 16)->default('ENROLLED');
                $table->string('source', 16)->default('BACK_OFFICE');
                $table->string('booking_reference', 24)->nullable();
                $table->uuid('invoice_id')->nullable()->index();
                $table->uuid('addons_invoice_id')->nullable()->index();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'class_id', 'status'], 'gce_class_idx');
                $table->unique(['tenant_id', 'booking_reference'], 'gce_ref_uq');
            });
        }

        if (! Schema::hasTable('group_course_attendances')) {
            Schema::create('group_course_attendances', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('enrollment_id')->constrained('group_course_enrollments')->cascadeOnDelete();
                $table->foreignUuid('class_id')->constrained('group_course_classes')->cascadeOnDelete();
                $table->date('attendance_date');
                $table->boolean('present')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['enrollment_id', 'attendance_date'], 'gca_day_uq');
                $table->index(['tenant_id', 'class_id', 'attendance_date'], 'gca_class_idx');
            });
        }

        if (! Schema::hasTable('group_course_transfers')) {
            Schema::create('group_course_transfers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('enrollment_id')->constrained('group_course_enrollments')->cascadeOnDelete();
                $table->foreignUuid('from_class_id')->constrained('group_course_classes')->cascadeOnDelete();
                $table->foreignUuid('to_class_id')->constrained('group_course_classes')->cascadeOnDelete();
                $table->string('reason', 200)->nullable();
                $table->uuid('transferred_by')->nullable();
                $table->dateTime('transferred_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'enrollment_id'], 'gct_enr_idx');
            });
        }
    }

    private function lessons(): void
    {
        if (Schema::hasTable('ski_lessons')) {
            return;
        }

        Schema::create('ski_lessons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('reference', 24);
            $table->foreignUuid('instructor_id')->nullable()->constrained('ski_instructors')->nullOnDelete();
            $table->foreignUuid('requested_instructor_id')->nullable()->constrained('ski_instructors')->nullOnDelete();
            $table->boolean('is_nominated')->default(false);
            $table->string('assignment_method', 16)->default('PENDING');
            $table->string('discipline', 30);
            $table->string('language_code', 8);
            $table->foreignUuid('level_id')->nullable()->constrained('group_course_levels')->nullOnDelete();
            $table->date('lesson_date');
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->unsignedSmallInteger('slot_minutes');
            $table->unsignedSmallInteger('slot_count')->default(1);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('participants')->default(1);
            $table->foreignUuid('customer_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('customer_name', 160);
            $table->string('customer_email', 160)->nullable();
            $table->string('customer_phone', 40)->nullable();
            $table->string('meeting_point', 160)->nullable();
            $table->decimal('price_net', 12, 2)->default(0);
            $table->string('vat_regime', 24)->default('IT_EXEMPT_ART10_N20');
            $table->string('status', 16)->default('REQUESTED');
            $table->string('source', 16)->default('BACK_OFFICE');
            $table->uuid('invoice_id')->nullable()->index();
            $table->uuid('addons_invoice_id')->nullable()->index();
            $table->dateTime('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reference'], 'skl_ref_uq');
            $table->index(['tenant_id', 'lesson_date', 'start_time'], 'skl_day_idx');
            $table->index(['tenant_id', 'instructor_id', 'lesson_date'], 'skl_ins_idx');
            $table->index(['tenant_id', 'status', 'lesson_date'], 'skl_status_idx');
        });
    }

    private function packageItems(): void
    {
        if (Schema::hasTable('ski_lesson_package_items')) {
            return;
        }

        Schema::create('ski_lesson_package_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('lesson_id')->nullable()->constrained('ski_lessons')->cascadeOnDelete();
            $table->foreignUuid('enrollment_id')->nullable()->constrained('group_course_enrollments')->cascadeOnDelete();
            $table->string('item_type', 24);
            $table->string('description', 200)->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->unsignedSmallInteger('days')->default(1);
            $table->decimal('unit_price_net', 12, 2)->default(0);
            $table->decimal('vat_rate', 6, 2)->default(0);
            $table->foreignUuid('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('status', 12)->default('RESERVED');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'lesson_id'], 'sklp_lesson_idx');
            $table->index(['tenant_id', 'enrollment_id'], 'sklp_enr_idx');
        });
    }

    private function payouts(): void
    {
        if (! Schema::hasTable('instructor_payout_statements')) {
            Schema::create('instructor_payout_statements', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('instructor_id')->constrained('ski_instructors')->restrictOnDelete();
                $table->string('period_month', 7);
                $table->string('reference', 32);
                $table->decimal('hours_total', 10, 2)->default(0);
                $table->decimal('private_hours', 10, 2)->default(0);
                $table->decimal('group_hours', 10, 2)->default(0);
                $table->decimal('gross_total', 14, 2)->default(0);
                $table->decimal('retention_total', 14, 2)->default(0);
                $table->decimal('withholding_percent', 6, 2)->default(0);
                $table->decimal('withholding_amount', 14, 2)->default(0);
                $table->decimal('net_payable', 14, 2)->default(0);
                $table->string('regime', 30);
                $table->string('status', 12)->default('DRAFT');
                $table->dateTime('issued_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'instructor_id', 'period_month'], 'ips_period_uq');
                $table->unique(['tenant_id', 'reference'], 'ips_ref_uq');
                $table->index(['tenant_id', 'period_month', 'status'], 'ips_month_idx');
            });
        }

        if (! Schema::hasTable('instructor_commissions')) {
            Schema::create('instructor_commissions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('instructor_id')->constrained('ski_instructors')->restrictOnDelete();
                $table->string('source_type', 12);
                $table->uuid('source_id');
                $table->date('work_date');
                $table->decimal('hours', 8, 2);
                $table->decimal('gross_fee', 12, 2);
                $table->string('retention_mode', 20);
                $table->decimal('retention_value', 10, 4)->default(0);
                $table->decimal('retention_amount', 12, 2)->default(0);
                $table->decimal('net_fee', 12, 2);
                $table->foreignUuid('payout_statement_id')->nullable()->constrained('instructor_payout_statements')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'source_type', 'source_id', 'instructor_id'], 'ic_source_uq');
                $table->index(['tenant_id', 'instructor_id', 'work_date'], 'ic_period_idx');
            });
        }
    }
};
