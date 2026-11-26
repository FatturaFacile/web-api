<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SCIM 2.0 Groups: the groups pushed by the identity provider with their members, the table that maps a group to a
     * tenant role, and the provisioning log of every SCIM operation. All tenant-scoped; additive only. The two
     * tenant_user columns remember the role a group mapping granted and the role the member had before it, so a role
     * that was not produced by a mapping is never touched.
     */
    public function up(): void
    {
        Schema::create('scim_groups', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('display_name', 255);
            $table->string('external_id', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'display_name']);
            $table->index(['tenant_id', 'external_id']);
        });

        Schema::create('scim_group_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('scim_group_id')->constrained('scim_groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['scim_group_id', 'user_id']);
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('scim_group_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('group_name', 255);
            $table->string('role', 60);
            $table->timestamps();

            $table->unique(['tenant_id', 'group_name']);
        });

        Schema::create('scim_provisioning_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('resource', 20);
            $table->string('operation', 30);
            $table->string('status', 20);
            $table->uuid('resource_id')->nullable();
            $table->string('resource_name', 255)->nullable();
            $table->uuid('scim_token_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->string('scim_granted_role', 60)->nullable();
            $table->string('scim_base_role', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_user', function (Blueprint $table): void {
            $table->dropColumn(['scim_granted_role', 'scim_base_role']);
        });

        Schema::dropIfExists('scim_provisioning_logs');
        Schema::dropIfExists('scim_group_mappings');
        Schema::dropIfExists('scim_group_members');
        Schema::dropIfExists('scim_groups');
    }
};
