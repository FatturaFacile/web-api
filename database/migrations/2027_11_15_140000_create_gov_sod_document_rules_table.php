<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-document segregation of duties: one rule per kind of document and company says whether the person who
     * prepared a payment, purchase order, journal or payroll run can also approve or release it.
     */
    public function up(): void
    {
        Schema::create('gov_sod_document_rules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->string('severity', 10)->default('block');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gov_sod_document_rules');
    }
};
