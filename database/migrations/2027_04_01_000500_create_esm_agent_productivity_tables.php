<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent productivity: canned responses, macros and saved ticket views. A row with an owner is personal, a shared row
 * (is_shared) is seen by every agent of the tenant. New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esm_canned_responses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->string('title', 150);
            $table->string('category', 80)->nullable();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_shared']);
            $table->index(['tenant_id', 'owner_user_id']);
        });

        Schema::create('esm_macros', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->string('name', 150);
            $table->text('description')->nullable();
            $table->jsonb('actions');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_shared']);
            $table->index(['tenant_id', 'owner_user_id']);
        });

        Schema::create('esm_saved_views', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->string('name', 150);
            $table->jsonb('filters');
            $table->unsignedInteger('position')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_shared']);
            $table->index(['tenant_id', 'owner_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_saved_views');
        Schema::dropIfExists('esm_macros');
        Schema::dropIfExists('esm_canned_responses');
    }
};
