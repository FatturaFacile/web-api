<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A contact is a company or a person: the company name is required for the first, the last name for the second.
 * The column is nullable (contacts created before it, by imports or by other modules, keep working); the existing rows
 * are classified once: a row with a company name is a company, one with only personal names is a person.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contacts') || Schema::hasColumn('contacts', 'legal_type')) {
            return;
        }

        Schema::table('contacts', static function (Blueprint $table): void {
            $table->string('legal_type', 10)->nullable()->after('type');
        });

        DB::table('contacts')
            ->whereNull('legal_type')
            ->whereNotNull('company_name')
            ->where('company_name', '!=', '')
            ->update(['legal_type' => 'company']);

        DB::table('contacts')
            ->whereNull('legal_type')
            ->where(static function ($query): void {
                $query->whereNotNull('last_name')->orWhereNotNull('first_name');
            })
            ->update(['legal_type' => 'person']);
    }

    /**
     * The classification may have been chosen by users since: the column is kept.
     */
    public function down(): void {}
};
