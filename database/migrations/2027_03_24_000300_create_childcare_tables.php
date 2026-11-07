<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kindergartens and day care. Personal and health data of children and of their reference persons is stored encrypted
 * (text columns written through encrypted casts) and the models of this module are not audited, so that it never
 * reaches the audit ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cc_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('cc_children', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('reference_code', 30);
            $table->foreignUuid('group_id')->nullable()->constrained('cc_groups')->nullOnDelete();
            $table->foreignUuid('billing_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->text('first_name');
            $table->text('last_name');
            $table->text('birth_date')->nullable();
            $table->text('allergies')->nullable();
            $table->text('dietary_notes')->nullable();
            $table->text('health_notes')->nullable();
            $table->text('subsidy_inputs')->nullable();
            $table->string('subsidy_canton', 2)->nullable();
            $table->date('enrolled_on');
            $table->date('left_on')->nullable();
            $table->string('status', 10)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'reference_code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('cc_reference_persons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->cascadeOnDelete();
            $table->text('full_name');
            $table->text('phone')->nullable();
            $table->text('email')->nullable();
            $table->string('relationship', 30);
            $table->boolean('is_guardian')->default(false);
            $table->boolean('can_pick_up')->default(false);
            $table->foreignUuid('portal_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'child_id']);
            $table->index(['tenant_id', 'portal_contact_id']);
        });

        Schema::create('cc_weekly_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->json('days');
            $table->timestamps();

            $table->index(['tenant_id', 'child_id', 'valid_from']);
        });

        Schema::create('cc_attendances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 10)->default('present');
            $table->string('absence_reason', 20)->nullable();
            $table->timestamp('check_in_at')->nullable();
            $table->timestamp('check_out_at')->nullable();
            $table->foreignUuid('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('picked_up_by')->nullable()->constrained('cc_reference_persons')->nullOnDelete();
            $table->text('temperature')->nullable();
            $table->timestamps();

            $table->unique(['child_id', 'date']);
            $table->index(['tenant_id', 'date']);
        });

        Schema::create('cc_attendance_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('attendance_id')->constrained('cc_attendances')->cascadeOnDelete();
            $table->string('field', 30);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('reason', 500);
            $table->foreignUuid('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'attendance_id']);
        });

        Schema::create('cc_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->cascadeOnDelete();
            $table->date('date');
            $table->string('category', 20);
            $table->text('body');
            $table->boolean('visible_to_family')->default(false);
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'child_id', 'date']);
        });

        Schema::create('cc_staff_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('group_id')->nullable()->constrained('cc_groups')->nullOnDelete();
            $table->date('date');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'date']);
        });

        Schema::create('cc_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedTinyInteger('weekday');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->unsignedSmallInteger('capacity')->default(0);
            $table->decimal('monthly_fee', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'weekday']);
        });

        Schema::create('cc_activity_enrolments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('activity_id')->constrained('cc_activities')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->cascadeOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'activity_id']);
        });

        Schema::create('cc_fee_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('basis', 15);
            $table->decimal('amount', 15, 2);
            $table->decimal('meal_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2);
            $table->string('currency', 3)->default('CHF');
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('cc_child_fees', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->cascadeOnDelete();
            $table->foreignUuid('fee_plan_id')->constrained('cc_fee_plans')->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'child_id']);
        });

        Schema::create('cc_subsidy_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('canton', 2);
            $table->string('name', 150);
            $table->string('method', 30);
            $table->json('parameters');
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('source_reference', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignUuid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'canton', 'valid_from']);
        });

        Schema::create('cc_monthly_bills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->restrictOnDelete();
            $table->string('period', 7);
            $table->unsignedSmallInteger('attended_days')->default(0);
            $table->unsignedSmallInteger('meals')->default(0);
            $table->decimal('gross_amount', 15, 2);
            $table->decimal('subsidy_amount', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2);
            $table->foreignUuid('subsidy_rule_id')->nullable()->constrained('cc_subsidy_rules')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();

            $table->unique(['child_id', 'period']);
            $table->index(['tenant_id', 'period']);
        });

        Schema::create('cc_document_requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->boolean('requires_signature')->default(false);
            $table->boolean('is_mandatory')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('cc_child_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->cascadeOnDelete();
            $table->foreignUuid('requirement_id')->nullable()->constrained('cc_document_requirements')->nullOnDelete();
            $table->string('title', 200);
            $table->string('status', 15)->default('requested');
            $table->string('file_path', 500)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->text('signed_name')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignUuid('signed_by_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'child_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('cc_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('child_id')->constrained('cc_children')->cascadeOnDelete();
            $table->string('direction', 12);
            $table->text('body');
            $table->foreignUuid('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('sender_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'child_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['cc_messages', 'cc_child_documents', 'cc_document_requirements', 'cc_monthly_bills', 'cc_subsidy_rules', 'cc_child_fees', 'cc_fee_plans', 'cc_activity_enrolments', 'cc_activities', 'cc_staff_shifts', 'cc_notes', 'cc_attendance_corrections', 'cc_attendances', 'cc_weekly_plans', 'cc_reference_persons', 'cc_children', 'cc_groups'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
