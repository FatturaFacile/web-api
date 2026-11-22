<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The journal templates (`accounting_templates`) and the year-end closing stages (`year_end_closing_stages`) were
 * created without the three audit columns every business table carries (created_by, updated_by, deleted_by). A
 * follow-up migration, because the tables already exist wherever the original migrations ran.
 */
return new class extends Migration
{
    private const TABLES = ['accounting_templates', 'year_end_closing_stages'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
                if (! Schema::hasColumn($tableName, $column)) {
                    Schema::table($tableName, function (Blueprint $table) use ($column): void {
                        $table->uuid($column)->nullable();
                    });
                }
            }
        }
    }

    public function down(): void
    {
        // Non-destructive enterprise migration policy: the audit columns hold the history of the rows.
    }
};
