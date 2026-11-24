<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stored responses of Idempotency-Key requests and per-user notification channel preferences.
     */
    public function up(): void
    {
        if (! Schema::hasTable('idempotency_keys')) {
            Schema::create('idempotency_keys', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('user_id');
                $table->string('idempotency_key');
                $table->string('method', 10);
                $table->string('path', 500);
                $table->string('request_hash', 64);
                $table->unsignedSmallInteger('response_status')->nullable();
                $table->text('response_headers')->nullable();
                $table->longText('response_body')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->unique(['user_id', 'idempotency_key']);
            });
        }

        if (! Schema::hasTable('notification_preferences')) {
            Schema::create('notification_preferences', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->string('channel', 30);
                $table->string('category', 60);
                $table->boolean('enabled')->default(true);
                $table->timestamps();

                $table->unique(['user_id', 'channel', 'category']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('idempotency_keys');
    }
};
