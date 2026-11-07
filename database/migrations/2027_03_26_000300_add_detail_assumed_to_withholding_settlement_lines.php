<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks a settlement line whose type comes from the withholding amount of the document because it carries no
     * DatiRitenuta detail. Existing lines came from a detail and stay false.
     */
    public function up(): void
    {
        Schema::table('withholding_settlement_lines', function (Blueprint $table): void {
            $table->boolean('detail_assumed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('withholding_settlement_lines', function (Blueprint $table): void {
            $table->dropColumn('detail_assumed');
        });
    }
};
