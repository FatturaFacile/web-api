<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('url');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->jsonb('events');
            $table->string('payload_version', 16)->default('v1');
            $table->text('secret');
            $table->string('secret_hint', 8);
            $table->text('previous_secret')->nullable();
            $table->timestamp('previous_secret_expires_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('disabled_at')->nullable();
            $table->string('disabled_reason', 255)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->uuid('event_id');
            $table->string('event_name', 100);
            $table->uuid('idempotency_key')->unique();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->jsonb('payload');
            $table->string('payload_version', 16);
            $table->boolean('is_test')->default(false);
            $table->char('dedupe_hash', 64)->nullable();
            $table->unsignedSmallInteger('last_response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('last_duration_ms')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'webhook_endpoint_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['webhook_endpoint_id', 'dedupe_hash']);
        });

        Schema::create('webhook_delivery_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('webhook_delivery_id')->constrained('webhook_deliveries')->cascadeOnDelete();
            $table->unsignedInteger('attempt_number');
            $table->boolean('succeeded')->default(false);
            $table->unsignedSmallInteger('response_code')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'webhook_delivery_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
    }
};
