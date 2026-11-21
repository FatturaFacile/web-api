<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geographic data residency, data sovereignty and BYOK/HYOK encryption (Mod 381): the sovereign infrastructure nodes, the
 * residency policy of a tenant or of one legal entity, the customer-managed key envelopes, the append-only hash-chained
 * KMS access audit, the cross-border adequacy matrix, the blocked egress attempts and the signed sovereignty attestations.
 * Dialect-agnostic: the append-only guarantee of the audit tables is enforced by the models, not by engine triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_sovereign_region_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('node_code', 32);
            $table->string('service_type', 32);
            $table->char('country_iso2', 2)->index();
            $table->string('canton_or_region', 64);
            $table->string('city', 96);
            $table->string('provider_name', 96);
            $table->text('endpoint_dsn_encrypted')->nullable();
            $table->jsonb('certifications_json')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'service_type']);
            $table->unique('node_code', 'uq_core_sovereign_node_code');
        });

        Schema::create('core_data_residency_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id')->nullable()->index();
            $table->string('scope_type', 32);
            $table->string('jurisdiction_zone', 32)->index();
            $table->foreignUuid('primary_db_node_id')->constrained('core_sovereign_region_nodes')->restrictOnDelete();
            $table->jsonb('replica_db_node_ids_json')->nullable();
            $table->foreignUuid('s3_storage_node_id')->constrained('core_sovereign_region_nodes')->restrictOnDelete();
            $table->foreignUuid('vector_db_node_id')->constrained('core_sovereign_region_nodes')->restrictOnDelete();
            $table->foreignUuid('ai_inference_node_id')->constrained('core_sovereign_region_nodes')->restrictOnDelete();
            $table->foreignUuid('backup_node_id')->constrained('core_sovereign_region_nodes')->restrictOnDelete();
            $table->foreignUuid('queue_node_id')->constrained('core_sovereign_region_nodes')->restrictOnDelete();
            $table->boolean('enforce_strict_egress_block')->default(true);
            $table->boolean('allow_aggregated_non_pii_consolidation')->default(true);
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            // A null legal entity (tenant default) does not collide in a unique index; the handler guards that case.
            $table->unique(['tenant_id', 'legal_entity_id'], 'uq_core_residency_tenant_entity');
        });

        Schema::create('core_byok_kms_configurations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('legal_entity_id')->nullable()->index();
            $table->string('key_management_mode', 24);
            $table->string('provider_type', 32);
            $table->string('external_key_uri', 512);
            $table->string('kmip_endpoint', 255)->nullable();
            $table->text('kms_credentials_encrypted')->nullable();
            $table->string('current_key_version', 96);
            $table->text('wrapped_dek_ciphertext')->nullable();
            $table->string('dek_wrap_algorithm', 32)->default('AES-256-GCM');
            $table->string('status', 32)->index();
            $table->unsignedInteger('dek_cache_ttl_seconds')->default(0);
            $table->unsignedInteger('rotation_interval_days')->default(90);
            $table->timestamp('last_rotated_at')->nullable();
            $table->timestamp('next_rotation_due_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'legal_entity_id', 'status']);
        });

        Schema::create('core_byok_key_access_audit', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->uuid('legal_entity_id')->nullable()->index();
            $table->uuid('kms_configuration_id')->index();
            $table->string('operation', 32);
            $table->string('key_version', 96)->nullable();
            $table->string('actor_type', 16);
            $table->string('actor_id', 64)->nullable();
            $table->string('source_node_code', 64);
            $table->string('source_ip', 64)->nullable();
            $table->string('outcome', 24);
            $table->unsignedInteger('latency_ms')->default(0);
            $table->char('previous_entry_hash', 64);
            $table->char('entry_hash', 64);
            $table->timestamp('occurred_at', 6)->index();

            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('core_cross_border_transfer_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('source_jurisdiction', 32);
            $table->char('destination_country_iso2', 2)->index();
            $table->string('destination_country_name', 96);
            $table->boolean('has_ch_adequacy')->default(false);
            $table->boolean('has_eu_adequacy')->default(false);
            $table->string('decision_status', 24);
            $table->string('legal_basis_reference', 160);
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'source_jurisdiction', 'destination_country_iso2'], 'idx_core_transfer_rule_lookup');
        });

        Schema::create('core_cross_border_blocked_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->uuid('legal_entity_id')->nullable()->index();
            $table->string('jurisdiction_zone', 32);
            $table->char('destination_country_iso2', 2)->nullable();
            $table->string('purpose', 64);
            $table->string('target', 255)->nullable();
            $table->string('decision_status', 24);
            $table->string('reason', 255);
            $table->timestamp('occurred_at', 6)->index();

            $table->index(['tenant_id', 'occurred_at']);
        });

        Schema::create('core_sovereignty_attestations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->uuid('legal_entity_id')->nullable()->index();
            $table->string('certificate_number', 48)->unique();
            $table->string('jurisdiction_zone', 32);
            $table->jsonb('topology_snapshot_json');
            $table->jsonb('byok_snapshot_json');
            $table->jsonb('compliance_frameworks_json');
            $table->char('sha256_payload_hash', 64);
            $table->text('digital_signature');
            $table->string('signer_key_fingerprint', 96);
            $table->timestamp('issued_at');
            $table->timestamp('valid_until');
            $table->timestamps();

            $table->index(['tenant_id', 'issued_at']);
        });
    }

    public function down(): void
    {
        foreach (['core_sovereignty_attestations', 'core_cross_border_blocked_attempts', 'core_cross_border_transfer_rules', 'core_byok_key_access_audit', 'core_byok_kms_configurations', 'core_data_residency_policies', 'core_sovereign_region_nodes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
