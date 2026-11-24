<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a participant owes and what was paid. The accounting of a payment stays in the invoices, payments and
     * journal tables of the company: these tables only keep the links to them.
     */
    public function up(): void
    {
        Schema::create('assoc_family_discounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('from_position');
            $table->decimal('percentage', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('assoc_fee_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->restrictOnDelete();
            $table->foreignUuid('fee_plan_id')->nullable()->constrained('assoc_fee_plans')->restrictOnDelete();
            $table->foreignUuid('payer_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('course_id')->nullable()->constrained('assoc_courses')->nullOnDelete();
            $table->foreignUuid('membership_id')->nullable()->constrained('assoc_memberships')->nullOnDelete();
            $table->foreignUuid('family_discount_id')->nullable()->constrained('assoc_family_discounts')->nullOnDelete();
            $table->string('description');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2);
            $table->decimal('per_attendance_amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('lessons_total')->nullable();
            $table->integer('lessons_remaining')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'member_id', 'status']);
            $table->index(['tenant_id', 'course_id']);
        });

        Schema::create('assoc_fee_instalments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('fee_assignment_id')->constrained('assoc_fee_assignments')->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->date('due_on');
            $table->decimal('amount', 12, 2);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'due_on']);
            $table->index('fee_assignment_id');
        });

        Schema::create('assoc_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 50);
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('sequential_number');
            $table->string('kind', 20);
            $table->foreignUuid('member_id')->constrained('assoc_members')->restrictOnDelete();
            $table->date('issued_on');
            $table->decimal('amount', 12, 2);
            $table->string('description');
            $table->text('holder');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'fiscal_year', 'sequential_number']);
            $table->index(['tenant_id', 'member_id']);
        });

        Schema::create('assoc_fee_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('fee_instalment_id')->constrained('assoc_fee_instalments')->restrictOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->uuid('payment_id')->nullable();
            $table->foreignUuid('receipt_id')->nullable()->constrained('assoc_receipts')->restrictOnDelete();
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'paid_on']);
            $table->index('fee_instalment_id');
        });

        Schema::create('assoc_payment_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('fee_instalment_id')->constrained('assoc_fee_instalments')->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('provider_reference', 100)->unique();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignUuid('fee_payment_id')->nullable()->constrained('assoc_fee_payments')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('assoc_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->uuid('subject_id');
            $table->integer('days_before');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'subject_id', 'days_before']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assoc_fee_instalments ADD CONSTRAINT assoc_fee_instalments_paid_check CHECK (paid_amount >= 0 AND paid_amount <= amount)');
            DB::statement('ALTER TABLE assoc_fee_assignments ADD CONSTRAINT assoc_fee_assignments_lessons_check CHECK (lessons_remaining IS NULL OR lessons_remaining >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_notices');
        Schema::dropIfExists('assoc_payment_links');
        Schema::dropIfExists('assoc_fee_payments');
        Schema::dropIfExists('assoc_receipts');
        Schema::dropIfExists('assoc_fee_instalments');
        Schema::dropIfExists('assoc_fee_assignments');
        Schema::dropIfExists('assoc_family_discounts');
    }
};
