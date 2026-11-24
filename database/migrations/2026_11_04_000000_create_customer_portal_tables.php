<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('portal_access_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('token_type', 30)->default('dashboard'); // dashboard, quote_approval, invoice_view, ticket_view
            $table->string('scope_type', 100)->nullable(); // e.g. App\Models\Quote
            $table->uuid('scope_id')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('last_accessed_at')->nullable();
            $table->boolean('is_single_use')->default(false);
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'expires_at']);
            $table->index(['scope_type', 'scope_id']);
        });

        Schema::create('portal_activity_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->foreignUuid('portal_access_token_id')->nullable()->constrained('portal_access_tokens')->nullOnDelete();
            $table->string('action', 50); // portal_login, quote_approved, quote_rejected, invoice_downloaded, service_report_downloaded, ticket_replied
            $table->string('subject_type', 50)->nullable();
            $table->uuid('subject_id')->nullable();
            $table->string('ip_address', 45);
            $table->text('user_agent')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at');

            $table->index(['tenant_id', 'contact_id']);
            $table->index(['tenant_id', 'action']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->string('author_type', 20)->default('customer'); // customer, technician, user
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_name', 150);
            $table->text('message');
            $table->boolean('is_internal')->default(false); // true: technician-only note, false: customer-facing
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'is_internal']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('portal_activity_logs');
        Schema::dropIfExists('portal_access_tokens');
    }
};
