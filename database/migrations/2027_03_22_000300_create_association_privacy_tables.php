<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data protection records of an association: the people and bodies with a role in the processing, the processing
     * activities in versions, consents with their proof, requests of the data subjects with their deadlines, and the map
     * of the assets with a confidentiality, integrity and availability rating. Names and contact data of individuals are
     * encrypted.
     *
     * Legal parameters are values that depend on the law and on the year (thresholds, ceilings, rates, retention
     * periods). Generic rows are shared and read-only for the companies; nothing is seeded, because every value must
     * come from a verified source.
     */
    public function up(): void
    {
        Schema::create('assoc_legal_parameters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 80);
            $table->unsignedSmallInteger('year');
            $table->string('value', 100);
            $table->string('source_reference', 500)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'code', 'year']);
        });

        Schema::table('assoc_members', function (Blueprint $table): void {
            $table->timestamp('processing_restricted_at')->nullable();
            $table->timestamp('erased_at')->nullable();
        });

        Schema::create('assoc_privacy_subjects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('name');
            $table->text('representative')->nullable();
            $table->text('tax_code')->nullable();
            $table->text('email')->nullable();
            $table->text('address')->nullable();
            $table->uuid('member_id')->nullable();
            $table->text('scope')->nullable();
            $table->date('appointed_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'role']);
        });

        Schema::create('assoc_processing_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('family_id');
            $table->unsignedSmallInteger('version')->default(1);
            $table->uuid('previous_version_id')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('data_subjects');
            $table->jsonb('data_categories');
            $table->boolean('special_categories')->default(false);
            $table->jsonb('purposes');
            $table->jsonb('legal_bases');
            $table->jsonb('collection_methods')->nullable();
            $table->text('storage')->nullable();
            $table->string('retention', 500);
            $table->jsonb('recipients')->nullable();
            $table->string('transfers_outside_eu', 500)->nullable();
            $table->text('security_measures')->nullable();
            $table->text('end_of_processing')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'family_id']);
            $table->unique(['family_id', 'version']);
        });

        Schema::create('assoc_consents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('member_id')->nullable();
            $table->uuid('contact_id')->nullable();
            $table->uuid('activity_id');
            $table->string('purpose', 500);
            $table->string('method', 30);
            $table->string('text_version', 100);
            $table->text('proof');
            $table->string('proof_hash', 64);
            $table->timestamp('given_at');
            $table->timestamp('withdrawn_at')->nullable();
            $table->uuid('recorded_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'member_id']);
            $table->index(['tenant_id', 'activity_id']);
        });

        Schema::create('assoc_data_subject_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->uuid('member_id')->nullable();
            $table->text('requester_name');
            $table->text('requester_contact')->nullable();
            $table->text('details')->nullable();
            $table->timestamp('received_at');
            $table->date('due_on');
            $table->date('extended_due_on')->nullable();
            $table->string('extension_reason', 500)->nullable();
            $table->string('status', 20)->default('received');
            $table->text('outcome')->nullable();
            $table->jsonb('erasure_report')->nullable();
            $table->uuid('handled_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'due_on']);
        });

        Schema::create('assoc_data_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 20);
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->unsignedTinyInteger('confidentiality');
            $table->unsignedTinyInteger('integrity');
            $table->unsignedTinyInteger('availability');
            $table->string('owner')->nullable();
            $table->jsonb('activity_family_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'kind']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX assoc_legal_parameters_tenant_unique ON assoc_legal_parameters (tenant_id, code, year) WHERE tenant_id IS NOT NULL AND deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_legal_parameters_generic_unique ON assoc_legal_parameters (code, year) WHERE tenant_id IS NULL AND deleted_at IS NULL');

            foreach (['confidentiality', 'integrity', 'availability'] as $column) {
                DB::statement("ALTER TABLE assoc_data_assets ADD CONSTRAINT assoc_data_assets_{$column}_check CHECK ({$column} BETWEEN 1 AND 4)");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_data_assets');
        Schema::dropIfExists('assoc_data_subject_requests');
        Schema::dropIfExists('assoc_consents');
        Schema::dropIfExists('assoc_processing_activities');
        Schema::dropIfExists('assoc_privacy_subjects');

        Schema::table('assoc_members', function (Blueprint $table): void {
            $table->dropColumn(['processing_restricted_at', 'erased_at']);
        });

        Schema::dropIfExists('assoc_legal_parameters');
    }
};
