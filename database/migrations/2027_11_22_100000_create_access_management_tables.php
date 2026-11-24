<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access management of a company: custom roles (a system role plus module levels), e-mail invitations, teams and
 * temporary delegations, the plan and its user / custom role limits on the tenant, the custom role of a membership and
 * the interface preferences of a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_custom_roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 80);
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('base_role', 50);
            $table->json('modules');
            $this->auditColumns($table);

            $table->index(['tenant_id', 'key']);
        });

        Schema::create('tenant_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('role', 50)->default('member');
            $table->uuid('custom_role_id')->nullable();
            $table->json('modules')->nullable();
            $table->json('team_ids')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 12)->default('pending');
            $table->uuid('invited_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $this->auditColumns($table);

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'email']);
        });

        Schema::create('access_teams', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->uuid('custom_role_id')->nullable();
            $this->auditColumns($table);

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('access_team_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('team_id')->constrained('access_teams')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $this->auditColumns($table);

            $table->unique(['team_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('access_delegations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('delegator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('delegate_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 50);
            $table->json('modules')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamp('revoked_at')->nullable();
            $this->auditColumns($table);

            $table->index(['tenant_id', 'delegate_id']);
            $table->index(['tenant_id', 'delegator_id']);
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->uuid('custom_role_id')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('plan_code', 30)->default('enterprise');
            $table->unsignedInteger('max_users')->nullable();
            $table->unsignedInteger('max_custom_roles')->nullable();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->json('ui_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ui_preferences');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['plan_code', 'max_users', 'max_custom_roles']);
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->dropColumn('custom_role_id');
        });

        Schema::dropIfExists('access_delegations');
        Schema::dropIfExists('access_team_members');
        Schema::dropIfExists('access_teams');
        Schema::dropIfExists('tenant_invitations');
        Schema::dropIfExists('tenant_custom_roles');
    }

    /**
     * The six audit columns every business table carries (see AuditColumnsTest).
     */
    private function auditColumns(Blueprint $table): void
    {
        $table->timestamps();
        $table->softDeletes();
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
    }
};
