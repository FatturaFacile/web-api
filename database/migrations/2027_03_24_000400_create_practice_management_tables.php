<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trust offices and law firms: activities link to the fee note that billed them, and each client gets a closing per
 * period. Additive on the populated billable_activities table: a nullable column only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billable_activities', function (Blueprint $table): void {
            $table->foreignUuid('fee_note_id')->nullable()->after('is_billed')->constrained('fee_notes')->nullOnDelete();
        });

        Schema::create('practice_period_closings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 15)->default('open');
            $table->json('checklist');
            $table->text('notes')->nullable();
            $table->foreignUuid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'client_id', 'period_start', 'period_end']);
            $table->index(['tenant_id', 'period_end', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_period_closings');

        Schema::table('billable_activities', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fee_note_id');
        });
    }
};
