<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Routing rules of the helpdesk and the SLA warning and breach stamps of a ticket, so each notice is sent once.
 * Additive and nullable, safe on populated tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esm_routing_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 150);
            $table->unsignedInteger('position')->default(100);
            $table->boolean('is_active')->default(true);
            $table->jsonb('conditions')->nullable();
            $table->jsonb('actions');
            $table->boolean('stop_processing')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active', 'position']);
        });

        Schema::table('esm_tickets', function (Blueprint $table): void {
            $table->timestamp('response_warning_sent_at')->nullable();
            $table->timestamp('resolution_warning_sent_at')->nullable();
            $table->timestamp('response_breached_at')->nullable();
            $table->timestamp('resolution_breached_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('esm_tickets', function (Blueprint $table): void {
            $table->dropColumn(['response_warning_sent_at', 'resolution_warning_sent_at', 'response_breached_at', 'resolution_breached_at']);
        });

        Schema::dropIfExists('esm_routing_rules');
    }
};
