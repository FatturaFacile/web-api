<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Single sign-on, second step: the client authentication method of the OIDC token endpoint, the end session endpoint
     * and the https post-logout redirect for the logout started by the service provider, the hash that binds a login to
     * the browser that started it, and the last id_token / SAML session index of an identity (encrypted, for the logout
     * hint). New nullable or defaulted columns only.
     */
    public function up(): void
    {
        Schema::table('sso_providers', function (Blueprint $table): void {
            $table->string('token_endpoint_auth_method', 30)->default('client_secret_post');
            $table->text('end_session_endpoint')->nullable();
            $table->text('post_logout_redirect_uri')->nullable();
        });

        Schema::table('sso_login_states', function (Blueprint $table): void {
            $table->string('browser_binding_hash', 64)->nullable();
        });

        Schema::table('saml_login_states', function (Blueprint $table): void {
            $table->string('browser_binding_hash', 64)->nullable();
        });

        Schema::table('sso_identities', function (Blueprint $table): void {
            $table->text('last_id_token')->nullable();
            $table->string('last_session_index', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sso_identities', function (Blueprint $table): void {
            $table->dropColumn(['last_id_token', 'last_session_index']);
        });

        Schema::table('saml_login_states', function (Blueprint $table): void {
            $table->dropColumn('browser_binding_hash');
        });

        Schema::table('sso_login_states', function (Blueprint $table): void {
            $table->dropColumn('browser_binding_hash');
        });

        Schema::table('sso_providers', function (Blueprint $table): void {
            $table->dropColumn(['token_endpoint_auth_method', 'end_session_endpoint', 'post_logout_redirect_uri']);
        });
    }
};
