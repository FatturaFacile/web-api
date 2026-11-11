<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The models Modules\Treasury\Models\BankTransaction and Modules\Inventory\Models\StockValuationSnapshot extend
 * BaseEnterpriseModel, which soft deletes, but their tables were created without deleted_at: every query on them
 * failed with an undefined column. Found by the registry driven tenant isolation test.
 *
 * Additive and idempotent: only a nullable column is added.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['bank_transactions', 'stock_valuation_snapshots'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->dropSoftDeletes();
                });
            }
        }
    }
};
