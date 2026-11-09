<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retainers (advance payments on account of fees) received from a client, and their application to fee notes.
     */
    public function up(): void
    {
        Schema::create('practice_retainers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('legal_case_id')->nullable()->constrained('legal_cases')->nullOnDelete();
            $table->date('received_on');
            $table->decimal('amount', 15, 2);
            $table->decimal('applied_amount', 15, 2)->default(0);
            $table->string('status', 10)->default('open');
            $table->string('reference', 100)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id', 'status']);
        });

        Schema::create('practice_retainer_applications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('retainer_id')->constrained('practice_retainers')->cascadeOnDelete();
            $table->foreignUuid('fee_note_id')->constrained('fee_notes')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->date('applied_on');
            $table->foreignUuid('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'fee_note_id']);
            $table->index(['tenant_id', 'retainer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_retainer_applications');
        Schema::dropIfExists('practice_retainers');
    }
};
