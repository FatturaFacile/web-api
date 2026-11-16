<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom roles, teams, temporary delegations and email invitations of a tenant, plus the subscription limits.
     */
    public function up(): void
    {
        Schema::create('tenant_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 60);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('base_role', 60)->default('member');
            $table->json('modules')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'key']);
        });

        Schema::create('tenant_teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->foreignUuid('custom_role_id')->nullable()->constrained('tenant_roles')->nullOnDelete();
            $this->audit($table);

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('tenant_team_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('tenant_teams')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $this->audit($table);

            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('tenant_access_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 60);
            $table->json('modules')->nullable();
            $table->string('reason', 255)->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->timestampTz('revoked_at')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'delegate_id', 'starts_at', 'ends_at'], 'tenant_delegations_lookup');
        });

        Schema::create('tenant_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('role', 60)->default('member');
            $table->foreignUuid('custom_role_id')->nullable()->constrained('tenant_roles')->nullOnDelete();
            $table->json('modules')->nullable();
            $table->json('team_ids')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->foreignUuid('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'email']);
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->foreignUuid('custom_role_id')->nullable()->constrained('tenant_roles')->nullOnDelete();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('plan_code', 40)->default('standard');
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_custom_roles')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['plan_code', 'max_users', 'max_custom_roles']);
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('custom_role_id');
        });

        foreach (['tenant_invitations', 'tenant_access_delegations', 'tenant_team_members', 'tenant_teams', 'tenant_roles'] as $name) {
            Schema::dropIfExists($name);
        }
    }

    private function audit(Blueprint $table): void
    {
        $table->timestamps();
        $table->softDeletes();
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
    }
};
