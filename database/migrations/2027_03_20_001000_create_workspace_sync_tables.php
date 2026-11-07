<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calendar and mail sync is per user: a connection exists only after that user consented, its tokens are encrypted
     * and only metadata of the synced items is stored, never message bodies.
     */
    public function up(): void
    {
        Schema::create('workspace_connections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('provider', 20);
            $table->jsonb('scopes')->default('[]');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('consent_given_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'user_id', 'provider'], 'uq_workspace_connection');
        });

        Schema::create('workspace_synced_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('connection_id')->index();
            $table->string('kind', 20);
            $table->string('external_id', 255);
            $table->string('subject', 500)->nullable();
            $table->string('counterpart', 255)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('connection_id')->references('id')->on('workspace_connections')->cascadeOnDelete();
            $table->unique(['connection_id', 'kind', 'external_id'], 'uq_workspace_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_synced_items');
        Schema::dropIfExists('workspace_connections');
    }
};
