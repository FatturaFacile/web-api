<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A confirmed WMS task posts its stock movement into the inventory module once: the posting key, the movement
 * reference and the posting time live on the task, so confirming or retrying never posts twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('wms_tasks')) {
            return;
        }

        Schema::table('wms_tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('wms_tasks', 'stock_posting_key')) {
                $table->string('stock_posting_key', 100)->nullable()->index();
            }

            if (! Schema::hasColumn('wms_tasks', 'stock_movement_id')) {
                $table->uuid('stock_movement_id')->nullable()->index();
            }

            if (! Schema::hasColumn('wms_tasks', 'stock_posted_at')) {
                $table->dateTime('stock_posted_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // Forward-only: posted movements stay referenced.
    }
};
