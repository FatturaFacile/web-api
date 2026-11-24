<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ecommerce_channels', function (Blueprint $table): void {
            $table->json('last_sync_summary')->nullable(); // outcome of the latest order pull
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ecommerce_channels', function (Blueprint $table): void {
            $table->dropColumn('last_sync_summary');
        });
    }
};
