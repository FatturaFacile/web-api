<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Public online enrolment. A form is found by its random token, which is what binds a public request to a tenant.
     * The data an applicant submits is encrypted.
     */
    public function up(): void
    {
        Schema::create('assoc_enrolment_forms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('public_token', 64)->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignUuid('year_id')->constrained('assoc_years')->restrictOnDelete();
            $table->foreignUuid('membership_type_id')->constrained('assoc_membership_types')->restrictOnDelete();
            $table->jsonb('fields')->nullable();
            $table->string('approval_mode', 20)->default('automatic');
            $table->boolean('is_active')->default(true);
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('assoc_enrolment_form_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_id')->constrained('assoc_enrolment_forms')->cascadeOnDelete();
            $table->string('title');
            $table->string('mode', 20);
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'form_id']);
        });

        Schema::create('assoc_enrolment_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('form_id')->constrained('assoc_enrolment_forms')->restrictOnDelete();
            $table->foreignUuid('member_id')->nullable()->constrained('assoc_members')->nullOnDelete();
            $table->foreignUuid('membership_id')->nullable()->constrained('assoc_memberships')->nullOnDelete();
            $table->string('status', 20)->default('submitted');
            $table->text('payload');
            $table->string('tracking_hash', 64)->unique();
            $table->string('decision_reference')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_notes', 1000)->nullable();
            $table->string('submitted_ip', 45)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('assoc_enrolment_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('application_id')->constrained('assoc_enrolment_applications')->cascadeOnDelete();
            $table->foreignUuid('form_document_id')->constrained('assoc_enrolment_form_documents')->restrictOnDelete();
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'application_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_enrolment_uploads');
        Schema::dropIfExists('assoc_enrolment_applications');
        Schema::dropIfExists('assoc_enrolment_form_documents');
        Schema::dropIfExists('assoc_enrolment_forms');
    }
};
