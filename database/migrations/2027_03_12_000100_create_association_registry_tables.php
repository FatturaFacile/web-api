<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The registry of an association: the people linked to the contacts of the company, their types and roles over the
     * years, custom fields, documents, minors with their guardians. Names, email and phone stay on the contact; the
     * personal and health data of this module are encrypted text columns.
     */
    public function up(): void
    {
        Schema::create('assoc_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('card_prefix', 20)->default('');
            $table->unsignedTinyInteger('card_padding')->default(6);
            $table->string('receipt_prefix', 20)->default('REC-');
            $table->string('default_receipt_kind', 20)->default('simple');
            $table->jsonb('fee_reminder_days');
            $table->jsonb('renewal_reminder_days');
            $table->unsignedSmallInteger('certificate_alert_days')->default(30);
            $table->unsignedSmallInteger('presence_max_hours')->default(12);
            $table->boolean('ledger_enabled')->default(true);
            $table->uuid('default_revenue_account_id')->nullable();
            $table->string('network_code', 40)->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('assoc_years', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('kind', 20)->default('associative');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'starts_on']);
        });

        Schema::create('assoc_registry_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('kind', 30)->default('custom');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'kind']);
        });

        Schema::create('assoc_social_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->boolean('is_single_holder')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('assoc_custom_fields', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('label');
            $table->string('type', 20)->default('text');
            $table->jsonb('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('assoc_families', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
        });

        Schema::create('assoc_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('family_id')->nullable()->constrained('assoc_families')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();
            $table->text('birth_date')->nullable();
            $table->text('birth_place')->nullable();
            $table->text('gender')->nullable();
            $table->text('tax_code')->nullable();
            $table->string('tax_code_hash', 64)->nullable();
            $table->text('identity_document')->nullable();
            $table->text('health_notes')->nullable();
            $table->text('allergies')->nullable();
            $table->text('emergency_contact')->nullable();
            $table->text('custom_fields')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('assoc_registry_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->foreignUuid('registry_type_id')->constrained('assoc_registry_types')->restrictOnDelete();
            $table->foreignUuid('year_id')->nullable()->constrained('assoc_years')->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'registry_type_id', 'valid_to']);
            $table->index(['member_id', 'valid_from']);
        });

        Schema::create('assoc_member_social_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->foreignUuid('social_role_id')->constrained('assoc_social_roles')->restrictOnDelete();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'social_role_id', 'valid_to']);
            $table->index(['member_id', 'valid_from']);
        });

        Schema::create('assoc_member_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->string('title');
            $table->string('category', 50)->default('other');
            $table->string('disk', 30);
            $table->string('path', 500);
            $table->string('original_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->date('expires_on')->nullable();
            $table->boolean('visible_to_member')->default(false);
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'expires_on']);
            $table->index('member_id');
        });

        Schema::create('assoc_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('created_by')->nullable();
            $table->string('original_name');
            $table->string('status', 20)->default('preview');
            $table->text('rows');
            $table->jsonb('summary');
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('assoc_guardianships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('minor_member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->foreignUuid('guardian_contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('relationship', 50)->default('parent');
            $table->boolean('is_primary')->default(false);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'guardian_contact_id']);
            $table->index('minor_member_id');
        });

        Schema::create('assoc_authorizations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('minor_member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->foreignUuid('guardianship_id')->nullable()->constrained('assoc_guardianships')->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('body_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->date('valid_until')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->text('signed_name')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 500)->nullable();
            $table->uuid('signed_with_token_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index('minor_member_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE assoc_years ADD CONSTRAINT assoc_years_dates_check CHECK (ends_on >= starts_on)');
            DB::statement('CREATE UNIQUE INDEX assoc_years_code_unique ON assoc_years (tenant_id, code) WHERE deleted_at IS NULL');
            DB::statement("CREATE UNIQUE INDEX assoc_registry_types_code_unique ON assoc_registry_types (COALESCE(tenant_id::text, ''), code) WHERE deleted_at IS NULL");
            DB::statement("CREATE UNIQUE INDEX assoc_social_roles_code_unique ON assoc_social_roles (COALESCE(tenant_id::text, ''), code) WHERE deleted_at IS NULL");
            DB::statement('CREATE UNIQUE INDEX assoc_custom_fields_code_unique ON assoc_custom_fields (tenant_id, code) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_members_contact_unique ON assoc_members (tenant_id, contact_id) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_members_tax_code_unique ON assoc_members (tenant_id, tax_code_hash) WHERE tax_code_hash IS NOT NULL AND deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_guardianships_unique ON assoc_guardianships (minor_member_id, guardian_contact_id) WHERE deleted_at IS NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_authorizations');
        Schema::dropIfExists('assoc_guardianships');
        Schema::dropIfExists('assoc_import_batches');
        Schema::dropIfExists('assoc_member_documents');
        Schema::dropIfExists('assoc_member_social_roles');
        Schema::dropIfExists('assoc_registry_assignments');
        Schema::dropIfExists('assoc_members');
        Schema::dropIfExists('assoc_families');
        Schema::dropIfExists('assoc_custom_fields');
        Schema::dropIfExists('assoc_social_roles');
        Schema::dropIfExists('assoc_registry_types');
        Schema::dropIfExists('assoc_years');
        Schema::dropIfExists('assoc_settings');
    }
};
