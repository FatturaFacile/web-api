<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('transactional_outbox') && ! Schema::hasColumn('transactional_outbox', 'retry_after')) {
            Schema::table('transactional_outbox', function (Blueprint $table): void {
                $table->timestampTz('retry_after')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('transactional_outbox') && Schema::hasColumn('transactional_outbox', 'retry_after')) {
            Schema::table('transactional_outbox', function (Blueprint $table): void {
                $table->dropColumn('retry_after');
            });
        }
    }
};
