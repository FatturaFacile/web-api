<?php

declare(strict_types=1);

use App\Services\Tenancy\TenantSchemaPolicy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applies the nullable tenant_id policy again to the tables created after 2027_05_20_000100: adds the missing nullable
 * UUID tenant_id (indexed) and the missing leading tenant_id index. Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Schema::getTables() as $row) {
            $table = $row['name'];

            if (TenantSchemaPolicy::isExcluded($table) || $table === 'tenants') {
                continue;
            }

            if (! Schema::hasColumn($table, 'tenant_id')) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->uuid('tenant_id')->nullable()->index();
                });

                continue;
            }

            foreach (Schema::getColumns($table) as $column) {
                if ($column['name'] === 'tenant_id' && ! $column['nullable']) {
                    Schema::table($table, static function (Blueprint $blueprint): void {
                        $blueprint->uuid('tenant_id')->nullable()->change();
                    });
                }
            }

            $indexed = false;

            foreach (Schema::getIndexes($table) as $index) {
                if (($index['columns'][0] ?? null) === 'tenant_id') {
                    $indexed = true;

                    break;
                }
            }

            if (! $indexed) {
                Schema::table($table, static function (Blueprint $blueprint): void {
                    $blueprint->index('tenant_id');
                });
            }
        }
    }

    public function down(): void {}
};
