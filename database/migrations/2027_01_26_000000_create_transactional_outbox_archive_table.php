<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('transactional_outbox_archive')) {
            Schema::create('transactional_outbox_archive', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->uuid('company_id')->index();
                $table->string('event_type', 150);
                $table->string('aggregate_type', 60);
                $table->uuid('aggregate_id');
                $table->json('payload_json');
                $table->timestampTz('occurred_at');
                $table->timestampTz('processed_at')->nullable();
                $table->integer('retry_count')->default(0);
                $table->string('status', 20)->default('processed');
                $table->text('error_message')->nullable();
                $table->timestampTz('retry_after')->nullable();
                $table->timestampTz('archived_at')->useCurrent();

                $table->index(['archived_at', 'tenant_id'], 'idx_outbox_archive_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('transactional_outbox_archive');
    }
};
