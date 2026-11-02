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
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dateTime('corrected_clock_in')->nullable()->index();
            $table->dateTime('corrected_clock_out')->nullable()->index();
            $table->unsignedInteger('corrected_break_minutes')->nullable();
            $table->uuid('applied_correction_id')->nullable();
        });

        Schema::create('time_entry_corrections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $table->foreignUuid('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('reason');
            $table->json('original_values');
            $table->json('proposed_values');
            $table->text('review_reason')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'time_entry_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_entry_corrections');
        Schema::table('time_entries', function (Blueprint $table): void {
            $table->dropIndex(['corrected_clock_in']);
            $table->dropIndex(['corrected_clock_out']);
            $table->dropColumn(['corrected_clock_in', 'corrected_clock_out', 'corrected_break_minutes', 'applied_correction_id']);
        });
    }
};
