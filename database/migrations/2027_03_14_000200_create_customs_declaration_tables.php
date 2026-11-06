<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Column sizes follow the e-dec schema v4.0 published by BAZG. The numeric codes (declaration time, transport mode
     * and the like) stay nullable: they come from the BAZG domains and are never defaulted here.
     */
    public function up(): void
    {
        Schema::create('customs_declarations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('declaration_type', 10);
            $table->string('status', 20)->default('draft');
            $table->string('trader_declaration_number', 22);
            $table->string('trader_reference', 35)->nullable();
            $table->string('mrn', 21)->nullable();
            $table->unsignedSmallInteger('mrn_version')->nullable();
            $table->uuid('dossier_id')->nullable();
            $table->uuid('delivery_note_id')->nullable();
            $table->uuid('consignor_contact_id')->nullable();
            $table->uuid('consignee_contact_id')->nullable();
            $table->uuid('importer_contact_id')->nullable();
            $table->unsignedTinyInteger('clearance_location')->nullable();
            $table->unsignedTinyInteger('declaration_time')->nullable();
            $table->unsignedTinyInteger('declaration_kind')->nullable();
            $table->string('language', 2)->default('de');
            $table->string('customs_office_number', 8)->nullable();
            $table->string('dispatch_country', 2)->nullable();
            $table->string('destination_country', 2)->nullable();
            $table->unsignedTinyInteger('transport_mode')->nullable();
            $table->unsignedTinyInteger('transportation_type')->nullable();
            $table->string('transportation_country', 2)->nullable();
            $table->string('transportation_number', 27)->nullable();
            $table->boolean('transport_in_container')->default(false);
            $table->jsonb('container_numbers')->nullable();
            $table->string('incoterms', 3)->nullable();
            $table->unsignedTinyInteger('invoice_currency_type')->nullable();
            $table->decimal('total_gross_mass', 15, 3)->default(0);
            $table->decimal('total_net_mass', 15, 3)->default(0);
            $table->decimal('total_statistical_value', 15, 2)->default(0);
            $table->decimal('total_vat_value', 15, 2)->default(0);
            $table->unsignedInteger('total_packages')->default(0);
            $table->string('gateway', 20)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->date('declared_on')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'trader_declaration_number'], 'customs_declarations_number_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'mrn']);
            $table->index(['tenant_id', 'dossier_id']);
            $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->nullOnDelete();
            $table->foreign('consignor_contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('consignee_contact_id')->references('id')->on('contacts')->nullOnDelete();
            $table->foreign('importer_contact_id')->references('id')->on('contacts')->nullOnDelete();
        });

        Schema::create('customs_declaration_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('declaration_id');
            $table->unsignedInteger('line_number');
            $table->uuid('product_id')->nullable();
            $table->string('trader_item_id', 16)->nullable();
            $table->string('description', 280);
            $table->string('commodity_code', 10);
            $table->unsignedSmallInteger('statistical_code')->nullable();
            $table->decimal('gross_mass', 12, 3)->default(0);
            $table->decimal('net_mass', 12, 3)->default(0);
            $table->decimal('additional_unit', 10, 1)->nullable();
            $table->unsignedTinyInteger('customs_clearance_type')->nullable();
            $table->unsignedTinyInteger('commercial_good')->nullable();
            $table->decimal('statistical_value', 12, 2)->default(0);
            $table->decimal('vat_value', 12, 2)->nullable();
            $table->unsignedTinyInteger('vat_code')->nullable();
            $table->decimal('duty_rate', 9, 2)->nullable();
            $table->string('origin_country', 2)->nullable();
            $table->boolean('preference')->default(false);
            $table->string('packaging_type', 3)->nullable();
            $table->unsignedInteger('package_quantity')->default(0);
            $table->string('packaging_reference', 42)->nullable();
            $table->jsonb('produced_documents')->nullable();
            $table->timestamps();

            $table->unique(['declaration_id', 'line_number']);
            $table->index(['tenant_id', 'commodity_code']);
            $table->foreign('declaration_id')->references('id')->on('customs_declarations')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });

        Schema::create('customs_gateway_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('declaration_id')->nullable();
            $table->string('direction', 10);
            $table->string('gateway', 20);
            $table->string('message_type', 40);
            $table->string('storage_path', 500);
            $table->char('sha256', 64);
            $table->string('outcome', 20)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'declaration_id']);
            $table->foreign('declaration_id')->references('id')->on('customs_declarations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customs_gateway_messages');
        Schema::dropIfExists('customs_declaration_items');
        Schema::dropIfExists('customs_declarations');
    }
};
