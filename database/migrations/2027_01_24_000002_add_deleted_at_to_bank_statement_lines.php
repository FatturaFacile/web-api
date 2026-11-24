<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_statement_lines') && ! Schema::hasColumn('bank_statement_lines', 'deleted_at')) {
            Schema::table('bank_statement_lines', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
        if (Schema::hasTable('bank_statements') && ! Schema::hasColumn('bank_statements', 'deleted_at')) {
            Schema::table('bank_statements', function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_statement_lines') && Schema::hasColumn('bank_statement_lines', 'deleted_at')) {
            Schema::table('bank_statement_lines', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
        if (Schema::hasTable('bank_statements') && Schema::hasColumn('bank_statements', 'deleted_at')) {
            Schema::table('bank_statements', function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
