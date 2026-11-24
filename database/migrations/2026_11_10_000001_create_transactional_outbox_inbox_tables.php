<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transactional_outbox')) {
            Schema::create('transactional_outbox', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->index();
                $table->string('event_type', 150);
                $table->string('aggregate_type', 60);
                $table->uuid('aggregate_id');
                $table->json('payload_json');
                $table->timestampTz('occurred_at')->useCurrent();
                $table->timestampTz('processed_at')->nullable();
                $table->integer('retry_count')->default(0);
                $table->string('status', 20)->default('pending');
                $table->text('error_message')->nullable();

                $table->index(['occurred_at', 'status'], 'idx_outbox_pending');
            });
        }

        if (! Schema::hasTable('transactional_inbox')) {
            Schema::create('transactional_inbox', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('message_id', 150)->unique();
                $table->string('event_type', 150);
                $table->json('payload_json');
                $table->timestampTz('received_at')->useCurrent();
                $table->timestampTz('processed_at')->nullable();
                $table->string('status', 20)->default('pending');

                $table->index(['status', 'received_at'], 'idx_inbox_pending');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_inbox');
        Schema::dropIfExists('transactional_outbox');
    }
};
