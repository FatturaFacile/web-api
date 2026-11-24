<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The protocol register of the correspondence, the volunteers with their activity, insurance and training, shifts
     * with availability and substitutions, expense reimbursements, and the fundraising campaigns the reimbursements can
     * be charged to. Reimbursements are posted to the general ledger: the journal entry is referenced, never copied.
     */
    public function up(): void
    {
        Schema::table('assoc_settings', function (Blueprint $table): void {
            $table->uuid('reimbursement_account_id')->nullable();
            $table->unsignedSmallInteger('shift_alert_days')->default(3);
            $table->unsignedSmallInteger('volunteer_expiry_alert_days')->default(30);
        });

        Schema::create('assoc_protocol_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedInteger('sequential_number');
            $table->string('number', 40);
            $table->string('direction', 20);
            $table->date('registered_on');
            $table->date('document_date')->nullable();
            $table->text('correspondent');
            $table->string('subject', 500);
            $table->string('category', 100)->nullable();
            $table->uuid('meeting_id')->nullable();
            $table->string('disk', 40)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'fiscal_year', 'sequential_number']);
        });

        Schema::create('assoc_volunteers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->string('activity');
            $table->string('category', 100)->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->boolean('is_occasional')->default(false);
            $table->string('insurer')->nullable();
            $table->string('insurance_policy', 100)->nullable();
            $table->date('insurance_expires_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'started_on']);
            $table->index(['tenant_id', 'member_id']);
        });

        Schema::create('assoc_volunteer_trainings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('volunteer_id')->constrained('assoc_volunteers')->cascadeOnDelete();
            $table->string('title');
            $table->date('completed_on');
            $table->date('expires_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'expires_on']);
        });

        Schema::create('assoc_shifts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->string('service', 100)->nullable();
            $table->string('location')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('required_volunteers')->default(1);
            $table->string('status', 20)->default('planned');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'starts_at']);
        });

        Schema::create('assoc_shift_availabilities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('assoc_shifts')->cascadeOnDelete();
            $table->foreignUuid('volunteer_id')->constrained('assoc_volunteers')->cascadeOnDelete();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->unique(['shift_id', 'volunteer_id']);
            $table->index('tenant_id');
        });

        Schema::create('assoc_shift_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('shift_id')->constrained('assoc_shifts')->cascadeOnDelete();
            $table->foreignUuid('volunteer_id')->constrained('assoc_volunteers')->cascadeOnDelete();
            $table->string('status', 30)->default('assigned');
            $table->uuid('replaced_by_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'shift_id']);
        });

        Schema::create('assoc_campaigns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('kind', 20)->default('regular');
            $table->text('purpose')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->decimal('target_amount', 14, 2)->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->uuid('revenue_account_id')->nullable();
            $table->uuid('expense_account_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('assoc_reimbursements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->uuid('volunteer_id')->nullable();
            $table->string('kind', 20);
            $table->date('incurred_on');
            $table->string('description', 500);
            $table->string('vehicle', 100)->nullable();
            $table->text('plate')->nullable();
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->decimal('unit_price', 10, 4)->nullable();
            $table->decimal('amount', 12, 2);
            $table->uuid('project_id')->nullable();
            $table->uuid('campaign_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->uuid('expense_account_id')->nullable();
            $table->string('status', 20)->default('submitted');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'incurred_on']);
            $table->index(['tenant_id', 'campaign_id']);
            $table->index(['tenant_id', 'project_id']);
        });

        Schema::create('assoc_reimbursement_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('reimbursement_id')->constrained('assoc_reimbursements')->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path', 500);
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'reimbursement_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX assoc_shift_assignments_unique ON assoc_shift_assignments (shift_id, volunteer_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_campaigns_code_unique ON assoc_campaigns (tenant_id, code) WHERE deleted_at IS NULL');
            DB::statement('ALTER TABLE assoc_shifts ADD CONSTRAINT assoc_shifts_period_check CHECK (ends_at > starts_at)');
            DB::statement('ALTER TABLE assoc_volunteers ADD CONSTRAINT assoc_volunteers_period_check CHECK (ended_on IS NULL OR ended_on >= started_on)');
            DB::statement('ALTER TABLE assoc_reimbursements ADD CONSTRAINT assoc_reimbursements_amount_check CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_reimbursement_attachments');
        Schema::dropIfExists('assoc_reimbursements');
        Schema::dropIfExists('assoc_campaigns');
        Schema::dropIfExists('assoc_shift_assignments');
        Schema::dropIfExists('assoc_shift_availabilities');
        Schema::dropIfExists('assoc_shifts');
        Schema::dropIfExists('assoc_volunteer_trainings');
        Schema::dropIfExists('assoc_volunteers');
        Schema::dropIfExists('assoc_protocol_entries');

        Schema::table('assoc_settings', function (Blueprint $table): void {
            $table->dropColumn(['reimbursement_account_id', 'shift_alert_days', 'volunteer_expiry_alert_days']);
        });
    }
};
