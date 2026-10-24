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
        Schema::create('construction_sites', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('code', 50)->index();
            $table->string('name');
            $table->string('address');
            $table->string('sia_norm_reference', 50)->nullable(); // Swiss SIA (e.g. SIA 118, SIA 102)
            $table->string('npk_code', 50)->nullable();          // Swiss NPK (Normpositionen-Katalog)
            $table->date('start_date')->nullable();
            $table->date('estimated_end_date')->nullable();
            $table->string('status', 30)->default('planning')->index(); // planning, active, paused, completed
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('progress_billings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->string('sal_number', 50)->index(); // e.g. SAL-01, SAL-02
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross_certified_amount', 15, 2);
            $table->decimal('retention_amount', 15, 2)->default(0); // Trattenuta di garanzia (e.g. 5% or 10%)
            $table->decimal('net_payable_amount', 15, 2);
            $table->string('status', 30)->default('draft')->index(); // draft, certified, invoiced
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['construction_site_id', 'sal_number']);
        });

        Schema::create('conformity_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->enum('type', ['it_dico', 'it_diri', 'ch_niv_oibt'])->index(); // IT DiCo / DiRi or Swiss NIV / OIBT
            $table->string('certificate_number', 50)->nullable();
            $table->string('inspector_name');
            $table->date('certified_at');
            $table->string('document_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conformity_certificates');
        Schema::dropIfExists('progress_billings');
        Schema::dropIfExists('construction_sites');
    }
};
