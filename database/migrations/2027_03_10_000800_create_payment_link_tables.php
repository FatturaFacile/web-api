<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->text('credentials')->nullable();
            $table->timestamps();
        });

        Schema::create('payment_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->constrained('invoices');
            $table->string('provider', 20);
            $table->string('provider_reference', 255)->nullable();
            $table->text('url')->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3);
            $table->string('status', 20)->default('open');
            $table->uuid('payment_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['provider', 'provider_reference']);
        });

        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('event_id', 255);
            $table->timestamps();

            $table->unique(['tenant_id', 'provider', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_links');
        Schema::dropIfExists('payment_gateway_settings');
    }
};
