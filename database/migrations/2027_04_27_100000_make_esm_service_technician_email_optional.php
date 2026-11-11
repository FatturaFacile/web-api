<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A technician provisioned from a hired employee used to receive an address invented from the name
     * (`first.last@service.local`) to satisfy NOT NULL. The column becomes optional so that the technician is created
     * with no address until a person states one. Existing rows are left as they are: an invented address cannot be told
     * apart from a real one without the company's review. Dropping NOT NULL is a catalog change only.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE esm_service_technicians ALTER COLUMN email DROP NOT NULL');
        } else {
            Schema::table('esm_service_technicians', function (Blueprint $table): void {
                $table->string('email')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        DB::statement("UPDATE esm_service_technicians SET email = 'technician-' || id || '@invalid.example' WHERE email IS NULL");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE esm_service_technicians ALTER COLUMN email SET NOT NULL');
        } else {
            Schema::table('esm_service_technicians', function (Blueprint $table): void {
                $table->string('email')->nullable(false)->change();
            });
        }
    }
};
