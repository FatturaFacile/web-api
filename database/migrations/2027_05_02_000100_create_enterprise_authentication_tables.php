<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enterprise authentication: the per-tenant security policy (password, lockout, session, second factor, SSO-only),
     * the OpenID Connect identity provider of a tenant with its login states and linked identities, the SCIM 2.0 bearer
     * tokens, the password history, and the columns that record how a token or a membership came to be. Additive only:
     * new tables and new nullable or defaulted columns.
     */
    public function up(): void
    {
        Schema::create('tenant_security_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('password_min_length')->default(10);
            $table->boolean('password_require_mixed_case')->default(true);
            $table->boolean('password_require_numbers')->default(true);
            $table->boolean('password_require_symbols')->default(true);
            $table->unsignedSmallInteger('password_history_count')->default(0);
            $table->unsignedSmallInteger('password_max_age_days')->nullable();
            $table->unsignedSmallInteger('lockout_max_attempts')->default(0);
            $table->unsignedSmallInteger('lockout_minutes')->default(15);
            $table->unsignedSmallInteger('session_idle_timeout_minutes')->nullable();
            $table->unsignedSmallInteger('max_concurrent_sessions')->nullable();
            $table->jsonb('two_factor_roles')->nullable();
            $table->boolean('sso_only')->default(false);
            $table->timestamps();
        });

        Schema::create('sso_providers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('issuer', 500);
            $table->text('authorization_endpoint')->nullable();
            $table->text('token_endpoint')->nullable();
            $table->text('jwks_uri')->nullable();
            $table->text('redirect_uri');
            $table->string('client_id', 255);
            $table->text('client_secret')->nullable();
            $table->string('scopes', 255)->default('openid email profile');
            $table->jsonb('allowed_email_domains');
            $table->string('role_claim', 120)->nullable();
            $table->jsonb('role_mapping')->nullable();
            $table->string('default_role', 60)->nullable();
            $table->boolean('sync_role_on_login')->default(false);
            $table->boolean('jit_provisioning')->default(false);
            $table->boolean('enabled')->default(false);
            $table->timestamp('discovered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sso_login_states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sso_provider_id')->constrained('sso_providers')->cascadeOnDelete();
            $table->string('state_hash', 64)->unique();
            $table->string('nonce_hash', 64);
            $table->text('code_verifier');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'expires_at']);
        });

        Schema::create('sso_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('sso_provider_id')->constrained('sso_providers')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 255);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['sso_provider_id', 'subject']);
            $table->unique(['sso_provider_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('scim_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('token_hint', 12);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });

        Schema::create('password_histories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('password_hash', 255);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedSmallInteger('failed_login_attempts')->default(0);
            $table->timestamp('login_locked_until')->nullable();
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->string('scim_external_id', 255)->nullable();
            $table->string('provisioned_via', 20)->nullable();
            $table->timestamp('suspended_at')->nullable();

            $table->index('scim_external_id');
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->string('auth_method', 20)->default('password');
            $table->uuid('auth_tenant_id')->nullable();
            $table->timestamp('last_activity_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['auth_method', 'auth_tenant_id', 'last_activity_at']);
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->dropIndex(['scim_external_id']);
            $table->dropColumn(['scim_external_id', 'provisioned_via', 'suspended_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['failed_login_attempts', 'login_locked_until']);
        });

        Schema::dropIfExists('password_histories');
        Schema::dropIfExists('scim_tokens');
        Schema::dropIfExists('sso_identities');
        Schema::dropIfExists('sso_login_states');
        Schema::dropIfExists('sso_providers');
        Schema::dropIfExists('tenant_security_policies');
    }
};
