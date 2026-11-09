<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement('ALTER TABLE esm_service_technicians ALTER COLUMN email DROP NOT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE esm_service_technicians SET email = 'technician-' || id || '@invalid.example' WHERE email IS NULL");
        DB::statement('ALTER TABLE esm_service_technicians ALTER COLUMN email SET NOT NULL');
    }
};
