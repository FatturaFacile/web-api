<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk notifications of the staff (in-app inbox) and the preferences of each user per event and channel. Without a
 * preference row every channel is on. New tables only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esm_helpdesk_notification_preferences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event', 40);
            $table->boolean('mail')->default(true);
            $table->boolean('in_app')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'user_id', 'event']);
        });

        Schema::create('esm_helpdesk_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->nullable()->constrained('esm_tickets')->cascadeOnDelete();
            $table->string('event', 40);
            $table->string('title', 255);
            $table->text('body')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_helpdesk_notifications');
        Schema::dropIfExists('esm_helpdesk_notification_preferences');
    }
};
