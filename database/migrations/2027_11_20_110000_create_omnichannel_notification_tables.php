<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Omnichannel notifications: delivery log, mobile devices (FCM/APNs), admin alert rules with their fired state, the
 * digest queue and the Telegram / WhatsApp addresses of a user.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_deliveries')) {
            Schema::create('notification_deliveries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->uuid('notification_id')->nullable()->index();
                $table->string('category', 60);
                $table->string('channel', 30);
                $table->string('title', 255)->nullable();
                $table->string('deep_link', 500)->nullable();
                $table->string('status', 12)->default('sent')->index();
                $table->text('error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_devices')) {
            Schema::create('notification_devices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->string('platform', 10);
                $table->string('token', 500)->unique();
                $table->string('name', 120)->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_alert_rules')) {
            Schema::create('notification_alert_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->string('name', 160);
                $table->string('metric', 60);
                $table->decimal('threshold', 15, 2);
                $table->string('currency', 3)->nullable();
                $table->string('category', 60)->default('billing');
                $table->json('recipient_user_ids')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_alert_rule_hits')) {
            Schema::create('notification_alert_rule_hits', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('rule_id')->index();
                $table->string('subject_id', 64);
                $table->timestamp('fired_at');

                $table->unique(['rule_id', 'subject_id']);
            });
        }

        if (! Schema::hasTable('notification_digest_items')) {
            Schema::create('notification_digest_items', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->string('channel', 30);
                $table->string('category', 60);
                $table->string('title', 255);
                $table->text('body')->nullable();
                $table->string('deep_link', 500)->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('notification_settings') && ! Schema::hasColumn('notification_settings', 'telegram_chat_id')) {
            Schema::table('notification_settings', function (Blueprint $table): void {
                $table->string('telegram_chat_id', 40)->nullable();
                $table->string('telegram_link_token', 64)->nullable()->unique();
                $table->string('whatsapp_number', 20)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['notification_digest_items', 'notification_alert_rule_hits', 'notification_alert_rules', 'notification_devices', 'notification_deliveries'] as $table) {
            Schema::dropIfExists($table);
        }

        if (Schema::hasColumn('notification_settings', 'telegram_chat_id')) {
            Schema::table('notification_settings', function (Blueprint $table): void {
                $table->dropColumn(['telegram_chat_id', 'telegram_link_token', 'whatsapp_number']);
            });
        }
    }
};
