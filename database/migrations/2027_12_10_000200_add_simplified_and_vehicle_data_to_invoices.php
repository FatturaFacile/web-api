<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `sdi_simplified` sends the invoice as a simplified FatturaPA (FSM10); `sdi_vehicle` carries the DatiVeicoli block of
     * an intra-EU vehicle sale (registration date and mileage).
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->boolean('sdi_simplified')->default(false);
            $table->json('sdi_vehicle')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropColumn(['sdi_simplified', 'sdi_vehicle']);
        });
    }
};
