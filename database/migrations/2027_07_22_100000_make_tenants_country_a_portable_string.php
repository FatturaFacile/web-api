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
     * tenants.country was a database enum limited to IT and CH, so a French, Spanish, German or American company could
     * not exist. It becomes a 2-letter string: the supported countries are validated by App\Enums\SupportedCountry.
     * Existing values and the index are preserved.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tenants DROP CONSTRAINT IF EXISTS tenants_country_check');
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('country', 2)->default('IT')->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * The column stays a string: narrowing back to IT/CH would fail on databases that already hold other countries.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('country', 2)->default('IT')->change();
        });
    }
};
