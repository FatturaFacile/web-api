<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_audit_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('tenant_id')->nullable();
            $table->string('device_id', 128);
            $table->string('origin', 40);
            $table->unsignedBigInteger('sequence_number');
            $table->char('previous_hash', 64);
            $table->char('payload_hash', 64);
            $table->char('record_hash', 64);
            $table->string('occurred_at', 40);
            $table->string('entity_type', 191);
            $table->string('entity_id', 191)->nullable();
            $table->string('action', 80);
            $table->text('payload')->nullable();
            $table->text('metadata')->nullable();
            $table->string('batch_id', 128)->nullable();
            $table->string('audit_log_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamps();

            // One slot per device sequence number: a replayed batch is acknowledged, a different hash is a conflict.
            $table->unique(['tenant_id', 'device_id', 'sequence_number'], 'offline_audit_device_sequence_unique');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_audit_entries');
    }
};
