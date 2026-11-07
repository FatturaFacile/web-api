<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_channels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('driver', 20);
            $table->boolean('is_enabled')->default(true);
            $table->text('credentials')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'channel']);
        });

        Schema::create('delivery_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('driver', 20);
            $table->string('document_type', 30);
            $table->uuid('document_id');
            $table->string('recipient', 255);
            $table->string('status', 20);
            $table->string('provider_reference', 100)->nullable();
            $table->text('error')->nullable();
            $table->uuid('sent_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_logs');
        Schema::dropIfExists('communication_channels');
    }
};
