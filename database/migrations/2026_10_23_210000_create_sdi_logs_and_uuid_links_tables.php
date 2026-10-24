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
        Schema::create('sdi_uuid_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('provider', 30);
            $table->string('sdi_uuid', 64);
            $table->timestamps();

            // Webhooks carry no tenant, so the provider UUID must be globally unique.
            $table->unique(['provider', 'sdi_uuid']);
            $table->index('invoice_id');
        });

        Schema::create('ei_outbound_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('provider', 30);
            $table->string('action', 30)->default('send'); // send
            $table->string('status', 20); // success, failed
            $table->string('sdi_uuid', 64)->nullable();
            $table->string('file_name')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'created_at']);
        });

        Schema::create('ei_inbound_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignUuid('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('provider', 30);
            $table->string('event_type', 20); // RC, NS, MC, DT, NEC1, NEC2
            $table->string('sdi_uuid', 64)->nullable()->index();
            $table->string('fingerprint', 64)->unique(); // makes webhook delivery idempotent
            $table->json('payload')->nullable();
            $table->string('status', 20)->default('received'); // received, processed, ignored
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['invoice_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ei_inbound_logs');
        Schema::dropIfExists('ei_outbound_logs');
        Schema::dropIfExists('sdi_uuid_links');
    }
};
