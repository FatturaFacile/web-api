<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = [
        'hr_exit_interviews', 'hr_goal_check_ins', 'hr_goal_cycles', 'hr_goal_key_results', 'hr_goals',
        'journal_line_letterings',
    ];

    /**
     * The tenant policy is a nullable tenant_id on every table (a null tenant marks generic rows).
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'tenant_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('tenant_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Nullable is the policy; reverting would fail on generic rows.
    }
};
