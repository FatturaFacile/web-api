<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Contacts were limited to IT and CH; foreign customers and suppliers need any ISO 3166-1 alpha-2 code.
     * Existing values and the column indexes are preserved.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_country_check');
            DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_type_check');
        }

        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('country', 2)->default('IT')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * Narrowing back to IT/CH fails on databases that already hold other country codes.
     */
    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('country', 2)->default('IT')->change();
        });
    }
};
