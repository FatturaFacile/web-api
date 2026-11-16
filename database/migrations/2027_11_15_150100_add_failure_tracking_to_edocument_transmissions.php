<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A refused hand-over is kept in the history as `failed` with its error and the payload (encrypted by the model), so it
     * can be retried; the payload is erased as soon as a driver accepts it.
     */
    public function up(): void
    {
        Schema::table('edocument_transmissions', function (Blueprint $table): void {
            $table->text('error_message')->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('last_attempt_at')->nullable();
            $table->longText('payload')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('edocument_transmissions', function (Blueprint $table): void {
            $table->dropColumn(['error_message', 'attempts', 'last_attempt_at', 'payload']);
        });
    }
};
