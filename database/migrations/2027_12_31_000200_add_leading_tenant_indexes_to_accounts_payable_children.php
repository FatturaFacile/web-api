<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Child tables of the accounts payable module whose tenant_id had no index with tenant_id as leading column.
     *
     * @var array<string, list<string>>
     */
    private const INDEXES = [
        'ap_open_item_events' => ['tenant_id', 'open_item_id', 'occurred_at'],
        'ap_payment_run_items' => ['tenant_id', 'payment_run_id'],
        'ap_payment_run_offsets' => ['tenant_id', 'payment_run_id'],
        'ap_payment_run_transfers' => ['tenant_id', 'payment_run_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (! Schema::hasTable($table) || Schema::hasIndex($table, $columns)) {
                continue;
            }

            Schema::table($table, static function (Blueprint $blueprint) use ($columns): void {
                $blueprint->index($columns);
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, $columns)) {
                Schema::table($table, static function (Blueprint $blueprint) use ($columns): void {
                    $blueprint->dropIndex($columns);
                });
            }
        }
    }
};
