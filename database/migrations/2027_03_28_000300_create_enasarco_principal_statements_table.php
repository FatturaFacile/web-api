<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New table only: the quarterly Enasarco statement (distinta) of the tenant as a principal, one row per agent and
     * quarter, with the figures it was computed from and the ledger entry of the principal's share.
     */
    public function up(): void
    {
        Schema::create('enasarco_principal_statements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->foreignUuid('contact_id')->constrained('contacts')->restrictOnDelete();
            $table->string('agent_type', 20);
            $table->unsignedInteger('documents')->default(0);
            $table->decimal('commissions', 15, 2);
            $table->decimal('subject_before', 15, 2);
            $table->decimal('subject_base', 15, 2);
            $table->decimal('annual_ceiling', 15, 2);
            $table->decimal('rate_percent', 5, 2);
            $table->decimal('agent_share_percent', 5, 2);
            $table->decimal('agent_withheld', 15, 2);
            $table->decimal('principal_amount', 15, 2);
            $table->decimal('total_due', 15, 2);
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'year', 'quarter', 'contact_id'], 'enasarco_principal_statements_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enasarco_principal_statements');
    }
};
