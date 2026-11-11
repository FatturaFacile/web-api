<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SAML 2.0 service provider settings of a tenant (one per tenant, next to the OIDC one), their SP-initiated login
     * states (the AuthnRequest id for InResponseTo), and the link of an identity to a SAML provider. An identity belongs
     * to an OIDC provider or to a SAML provider, so sso_provider_id becomes nullable (relaxing a constraint, no data lost).
     */
    public function up(): void
    {
        Schema::create('saml_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('idp_entity_id', 500);
            $table->text('idp_sso_url');
            $table->text('idp_slo_url')->nullable();
            $table->jsonb('idp_certificates');
            $table->text('idp_metadata_url')->nullable();
            $table->timestamp('metadata_refreshed_at')->nullable();
            $table->string('email_attribute', 255)->nullable();
            $table->string('name_attribute', 255)->nullable();
            $table->string('role_attribute', 255)->nullable();
            $table->jsonb('role_mapping')->nullable();
            $table->string('default_role', 60)->nullable();
            $table->boolean('sync_role_on_login')->default(false);
            $table->boolean('jit_provisioning')->default(false);
            $table->jsonb('allowed_email_domains');
            $table->text('sp_private_key')->nullable();
            $table->text('sp_certificate')->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('saml_login_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('saml_provider_id')->constrained('saml_providers')->cascadeOnDelete();
            $table->string('relay_state_hash', 64)->unique();
            $table->text('request_id');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::table('sso_identities', function (Blueprint $table): void {
            $table->foreignUuid('saml_provider_id')->nullable()->constrained('saml_providers')->cascadeOnDelete();
            $table->unique(['saml_provider_id', 'subject']);
            $table->unique(['saml_provider_id', 'user_id']);
        });

        DB::statement('ALTER TABLE sso_identities ALTER COLUMN sso_provider_id DROP NOT NULL');
    }

    public function down(): void
    {
        Schema::table('sso_identities', function (Blueprint $table): void {
            $table->dropUnique(['saml_provider_id', 'subject']);
            $table->dropUnique(['saml_provider_id', 'user_id']);
            $table->dropConstrainedForeignId('saml_provider_id');
        });

        Schema::dropIfExists('saml_login_states');
        Schema::dropIfExists('saml_providers');
    }
};
