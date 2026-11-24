<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Link of an RMA to the DDT (transport document) generated when the repaired unit goes back to the customer. */
    public function up(): void
    {
        Schema::table('esm_rma_orders', function (Blueprint $table): void {
            $table->string('return_delivery_note_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('esm_rma_orders', function (Blueprint $table): void {
            $table->dropColumn('return_delivery_note_id');
        });
    }
};
