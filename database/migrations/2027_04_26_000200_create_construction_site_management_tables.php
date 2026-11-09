<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Site management of the construction trades: subcontractors with their contracts and certified work, the daily
     * site diary, and site documents (safety and compliance) with an expiry date.
     */
    public function up(): void
    {
        Schema::create('ct_subcontractors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('name', 190);
            $table->string('tax_id', 40)->nullable();
            $table->string('trade', 100)->nullable();
            $table->string('status', 12)->default('active');
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('ct_subcontracts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->foreignUuid('subcontractor_id')->constrained('ct_subcontractors')->restrictOnDelete();
            $table->foreignUuid('bid_id')->nullable()->constrained('ct_bids')->nullOnDelete();
            $table->string('description', 500);
            $table->decimal('contract_amount', 15, 2);
            $table->string('status', 12)->default('active');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'construction_site_id']);
            $table->index(['tenant_id', 'subcontractor_id']);
        });

        Schema::create('ct_subcontract_certifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('subcontract_id')->constrained('ct_subcontracts')->cascadeOnDelete();
            $table->foreignUuid('site_cost_id')->nullable()->constrained('ct_site_costs')->nullOnDelete();
            $table->date('certified_on');
            $table->decimal('amount', 15, 2);
            $table->string('description', 500);
            $table->timestamps();

            $table->index(['tenant_id', 'subcontract_id']);
        });

        Schema::create('ct_site_diary_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('weather', 100)->nullable();
            $table->unsignedSmallInteger('workers_present')->default(0);
            $table->text('work_performed');
            $table->text('incidents')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['construction_site_id', 'entry_date']);
            $table->index(['tenant_id', 'entry_date']);
        });

        Schema::create('ct_site_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->foreignUuid('subcontractor_id')->nullable()->constrained('ct_subcontractors')->nullOnDelete();
            $table->foreignUuid('dms_document_id')->nullable()->constrained('dms_documents')->nullOnDelete();
            $table->string('kind', 24);
            $table->string('title', 255);
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'construction_site_id']);
            $table->index(['tenant_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ct_site_documents');
        Schema::dropIfExists('ct_site_diary_entries');
        Schema::dropIfExists('ct_subcontract_certifications');
        Schema::dropIfExists('ct_subcontracts');
        Schema::dropIfExists('ct_subcontractors');
    }
};
