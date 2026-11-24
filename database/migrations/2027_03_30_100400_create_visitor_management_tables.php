<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Visitor management: visitors, their expected and actual visits, and the reception kiosks. Personal data columns are
     * text because they hold ciphertext (encrypted casts). New tables only: nothing existing is touched.
     */
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->text('first_name');
            $table->text('last_name');
            $table->text('company_name')->nullable();
            $table->text('email')->nullable();
            $table->text('phone')->nullable();
            $table->text('document_number')->nullable();
            $table->timestamp('last_visit_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'last_visit_at']);
        });

        Schema::create('visitor_kiosks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('device_key', 40)->unique();
            $table->string('secret_hash', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('visitor_visits', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('visitor_id')->constrained('visitors')->cascadeOnDelete();
            $table->foreignUuid('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expected_at');
            $table->timestamp('expected_until')->nullable();
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('expected');
            $table->string('badge_number', 30)->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->foreignUuid('kiosk_id')->nullable()->constrained('visitor_kiosks')->nullOnDelete();
            $table->timestamp('host_notified_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expected_at']);
            $table->index(['tenant_id', 'host_user_id']);
        });

        DB::statement("ALTER TABLE visitor_visits ADD CONSTRAINT visitor_visits_status_check CHECK (status IN ('expected', 'checked_in', 'checked_out', 'cancelled'))");
        DB::statement("CREATE UNIQUE INDEX visitor_visits_active_badge_unique ON visitor_visits (tenant_id, badge_number) WHERE status = 'checked_in' AND badge_number IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('visitor_visits');
        Schema::dropIfExists('visitor_kiosks');
        Schema::dropIfExists('visitors');
    }
};
