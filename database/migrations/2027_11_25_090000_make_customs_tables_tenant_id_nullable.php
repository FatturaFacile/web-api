<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant policy: every table has a nullable tenant_id that is a foreign key to tenants. The customs tables of the
     * cross-border hub were created with a required, unconstrained column.
     *
     * @var list<string>
     */
    private const array TABLES = ['customs_declaration_invoices', 'customs_import_bills'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('tenant_id')->nullable()->change();
            });

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['tenant_id']);
            });

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->uuid('tenant_id')->nullable(false)->change();
            });
        }
    }
};
