<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Segregation of duties: rules (two groups of conflicting duties), the register of violations found on the memberships
     * of the company, and the time-boxed exceptions approved by a second person. Allowed values are enforced by the
     * application, never by database constraints. New tables only.
     */
    public function up(): void
    {
        Schema::create('gov_sod_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('title', 190);
            $table->text('description')->nullable();
            $table->json('duties_a');
            $table->json('duties_b');
            $table->string('severity', 10)->default('warn');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('gov_sod_exceptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('rule_id');
            $table->uuid('user_id');
            $table->text('justification');
            $table->uuid('approved_by');
            $table->date('expires_at');
            $table->string('status', 10)->default('active');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'rule_id']);
            $table->index(['tenant_id', 'status', 'expires_at']);
        });

        Schema::create('gov_sod_violations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->uuid('rule_id');
            $table->uuid('user_id');
            $table->string('duty_a', 120);
            $table->string('duty_b', 120);
            $table->string('status', 10)->default('open');
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->uuid('exception_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'user_id', 'rule_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gov_sod_violations');
        Schema::dropIfExists('gov_sod_exceptions');
        Schema::dropIfExists('gov_sod_rules');
    }
};
