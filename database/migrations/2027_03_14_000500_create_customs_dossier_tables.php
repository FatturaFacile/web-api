<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freight forwarding: tariff grids, consolidations (groupage), shipment dossiers with quotes, charges, documents
     * and split deliveries, and the shipment requests coming from the customer portal.
     */
    public function up(): void
    {
        Schema::create('customs_tariff_grids', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            $table->string('service_type', 40);
            $table->string('currency', 3);
            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'service_type']);
        });

        Schema::create('customs_tariff_grid_rates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tariff_grid_id');
            $table->string('destination_country', 2)->nullable();
            $table->decimal('min_weight_kg', 12, 3)->default(0);
            $table->decimal('max_weight_kg', 12, 3)->nullable();
            $table->decimal('min_volume_m3', 12, 3)->default(0);
            $table->decimal('max_volume_m3', 12, 3)->nullable();
            $table->string('basis', 10);
            $table->decimal('rate', 12, 4);
            $table->decimal('minimum_charge', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'tariff_grid_id']);
            $table->foreign('tariff_grid_id')->references('id')->on('customs_tariff_grids')->cascadeOnDelete();
        });

        Schema::create('customs_service_discounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contact_id');
            $table->string('service_type', 40);
            $table->decimal('discount_percent', 5, 2);
            $table->timestamps();

            $table->unique(['tenant_id', 'contact_id', 'service_type'], 'customs_service_discounts_unique');
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
        });

        Schema::create('customs_consolidations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('reference', 40);
            $table->string('status', 20)->default('open');
            $table->string('carrier_name', 120)->nullable();
            $table->date('departure_on')->nullable();
            $table->decimal('freight_cost', 14, 2)->default(0);
            $table->string('currency', 3);
            $table->timestamp('allocated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'reference']);
        });

        Schema::create('customs_dossiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('dossier_number', 40);
            $table->string('status', 20)->default('open');
            $table->uuid('customer_contact_id');
            $table->uuid('consolidation_id')->nullable();
            $table->string('service_type', 40);
            $table->string('direction', 10);
            $table->string('origin_country', 2)->nullable();
            $table->string('destination_country', 2)->nullable();
            $table->string('incoterms', 3)->nullable();
            $table->decimal('gross_weight_kg', 12, 3)->default(0);
            $table->decimal('volume_m3', 12, 3)->default(0);
            $table->unsignedInteger('packages')->default(0);
            $table->string('currency', 3);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'dossier_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'customer_contact_id']);
            $table->foreign('customer_contact_id')->references('id')->on('contacts')->restrictOnDelete();
            $table->foreign('consolidation_id')->references('id')->on('customs_consolidations')->nullOnDelete();
        });

        Schema::create('customs_quotes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('dossier_id');
            $table->string('quote_number', 40);
            $table->string('status', 20)->default('draft');
            $table->boolean('is_spot')->default(false);
            $table->uuid('tariff_grid_id')->nullable();
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('currency', 3);
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'quote_number']);
            $table->index(['tenant_id', 'dossier_id']);
            $table->foreign('dossier_id')->references('id')->on('customs_dossiers')->cascadeOnDelete();
            $table->foreign('tariff_grid_id')->references('id')->on('customs_tariff_grids')->nullOnDelete();
        });

        Schema::create('customs_quote_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('quote_id');
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->decimal('unit_price', 14, 4);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->index(['tenant_id', 'quote_id']);
            $table->foreign('quote_id')->references('id')->on('customs_quotes')->cascadeOnDelete();
        });

        Schema::create('customs_dossier_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('dossier_id');
            $table->string('kind', 10);
            $table->string('category', 20);
            $table->boolean('is_direct')->default(true);
            $table->string('description', 255);
            $table->decimal('amount', 14, 2);
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->string('currency', 3);
            $table->uuid('supplier_contact_id')->nullable();
            $table->uuid('assessment_decision_id')->nullable();
            $table->uuid('consolidation_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->timestamps();

            $table->unique(['dossier_id', 'assessment_decision_id', 'kind'], 'customs_dossier_charges_assessment_unique');
            $table->index(['tenant_id', 'dossier_id']);
            $table->foreign('dossier_id')->references('id')->on('customs_dossiers')->cascadeOnDelete();
            $table->foreign('supplier_contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->nullOnDelete();
        });

        Schema::create('customs_dossier_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('dossier_id');
            $table->string('document_kind', 30);
            $table->string('file_name', 255);
            $table->string('storage_path', 500);
            $table->char('sha256', 64);
            $table->timestamps();

            $table->index(['tenant_id', 'dossier_id']);
            $table->foreign('dossier_id')->references('id')->on('customs_dossiers')->cascadeOnDelete();
        });

        Schema::create('customs_dossier_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('dossier_id');
            $table->uuid('delivery_note_id')->nullable();
            $table->unsignedInteger('packages')->default(0);
            $table->decimal('gross_weight_kg', 12, 3)->default(0);
            $table->date('delivered_on')->nullable();
            $table->string('recipient', 120)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'dossier_id']);
            $table->foreign('dossier_id')->references('id')->on('customs_dossiers')->cascadeOnDelete();
            $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->nullOnDelete();
        });

        Schema::create('customs_shipment_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('contact_id');
            $table->string('status', 20)->default('pending');
            $table->string('service_type', 40);
            $table->string('direction', 10);
            $table->string('origin_country', 2);
            $table->string('destination_country', 2);
            $table->decimal('gross_weight_kg', 12, 3);
            $table->decimal('volume_m3', 12, 3)->default(0);
            $table->unsignedInteger('packages');
            $table->date('pickup_on')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('dossier_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'contact_id']);
            $table->foreign('contact_id')->references('id')->on('contacts')->cascadeOnDelete();
            $table->foreign('dossier_id')->references('id')->on('customs_dossiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach ([
            'customs_shipment_requests', 'customs_dossier_deliveries', 'customs_dossier_documents', 'customs_dossier_charges',
            'customs_quote_lines', 'customs_quotes', 'customs_dossiers', 'customs_consolidations', 'customs_service_discounts',
            'customs_tariff_grid_rates', 'customs_tariff_grids',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
