<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per named lock of App\Support\Database\TransactionLock. Locking the row of a name serializes the
     * transactions that need it on every database, where an engine-specific advisory lock would not.
     */
    public function up(): void
    {
        Schema::create('transaction_locks', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_locks');
    }
};
