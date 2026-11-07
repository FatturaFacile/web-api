<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sections of an association (youth teams, a secondary seat, a summer event) with separate accounts: each section is
     * a cost centre of the general ledger, so its figures come from the postings and the consolidated view is the whole
     * body.
     */
    public function up(): void
    {
        Schema::create('assoc_sections', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->foreignUuid('cost_center_id')->constrained('cost_centers')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_sections');
    }
};
