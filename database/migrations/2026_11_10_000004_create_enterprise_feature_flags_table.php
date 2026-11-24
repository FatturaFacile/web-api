<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('enterprise_feature_flags')) {
            Schema::create('enterprise_feature_flags', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('feature_key', 100)->index();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('user_id')->nullable()->index();
                $table->boolean('is_enabled')->default(true);
                $table->json('payload_config')->nullable();
                $table->timestampsTz();

                $table->index(['feature_key', 'tenant_id'], 'idx_feature_tenant');
                $table->index(['feature_key', 'user_id'], 'idx_feature_user');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('enterprise_feature_flags');
    }
};
