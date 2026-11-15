<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_lead_scoring_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name', 150);
            $table->string('category', 20); // demographic, behavioral
            $table->string('field', 100)->nullable(); // demographic attribute or behavioral metadata key
            $table->string('event_type', 50)->nullable(); // behavioral event type
            $table->string('operator', 20)->default('exists'); // eq, in, gte, lte, between, contains, exists
            $table->jsonb('value')->nullable();
            $table->integer('points');
            $table->unsignedInteger('max_applications')->nullable();
            $table->unsignedInteger('decay_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active', 'category']);
            $table->index(['tenant_id', 'code']);
        });

        Schema::create('crm_lead_settings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('warm_threshold')->default(40);
            $table->unsignedSmallInteger('hot_threshold')->default(70);
            $table->unsignedSmallInteger('auto_qualify_threshold')->default(80);
            $table->string('dedup_mode', 20)->default('notify'); // auto_merge, notify
            $table->unsignedSmallInteger('dedup_levenshtein_max')->default(2);
            $table->decimal('dedup_similarity_min', 4, 3)->default(0.85);
            $table->string('default_country', 2)->default('IT');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_lead_settings');
        Schema::dropIfExists('crm_lead_scoring_rules');
    }
};
