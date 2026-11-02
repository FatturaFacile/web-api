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
        Schema::create('time_entry_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('type', 30);
            $table->dateTime('occurred_at', 6);
            $table->dateTime('recorded_at', 6);
            $table->foreignUuid('recorded_by')->constrained('users')->restrictOnDelete();
            $table->unique(['time_entry_id', 'sequence']);
            $table->index(['tenant_id', 'time_entry_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entry_events');
    }
};
