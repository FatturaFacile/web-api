<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cross-border trade compliance on top of the e-dec declaration tables: regime and transit data on the declaration,
     * the link between a declaration and the invoices it clears, import customs bills, and the product master data a
     * customs invoice needs (weights, Swiss HS code, supplementary unit, duty rate, preferential origin flag).
     */
    public function up(): void
    {
        Schema::table('customs_goods_declarations', function (Blueprint $table): void {
            $table->string('customs_regime', 10)->default('edec')->after('declaration_type');
            $table->string('transit_document_type', 4)->default('none')->after('customs_regime');
            $table->string('transit_guarantee_reference', 35)->nullable();
            $table->string('transit_departure_office', 8)->nullable();
            $table->string('transit_destination_office', 8)->nullable();
            $table->date('transit_deadline_on')->nullable();
            $table->timestamp('transit_discharged_at')->nullable();
            $table->string('border_crossing', 60)->nullable();
        });

        Schema::create('customs_declaration_invoices', function (Blueprint $table): void {
            $table->uuid('tenant_id')->nullable();
            $table->uuid('declaration_id');
            $table->uuid('invoice_id');
            $table->timestamps();

            $table->primary(['declaration_id', 'invoice_id']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->foreign('declaration_id')->references('id')->on('customs_goods_declarations')->cascadeOnDelete();
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
        });

        Schema::create('customs_import_bills', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('declaration_id')->nullable();
            $table->uuid('passive_invoice_id')->nullable();
            $table->string('bill_number', 40);
            $table->date('bill_date');
            $table->string('broker_invoice_reference', 50)->nullable();
            $table->string('currency', 3);
            $table->decimal('customs_value', 15, 2)->default(0);
            $table->decimal('duty_amount', 15, 2)->default(0);
            $table->decimal('ancillary_costs', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->decimal('import_vat_amount', 15, 2)->default(0);
            $table->string('status', 10)->default('draft');
            $table->uuid('journal_entry_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'bill_number']);
            $table->index(['tenant_id', 'bill_date']);
            $table->foreign('declaration_id')->references('id')->on('customs_goods_declarations')->nullOnDelete();
            $table->foreign('passive_invoice_id')->references('id')->on('passive_invoices')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->string('swiss_hs_code', 8)->nullable();
            $table->string('supplementary_unit', 10)->nullable();
            $table->decimal('customs_duty_rate', 7, 4)->nullable();
            $table->decimal('net_weight_kg', 12, 3)->nullable();
            $table->decimal('gross_weight_kg', 12, 3)->nullable();
            $table->unsignedInteger('packages_count')->nullable();
            $table->boolean('preferential_origin_eligible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['swiss_hs_code', 'supplementary_unit', 'customs_duty_rate', 'net_weight_kg', 'gross_weight_kg', 'packages_count', 'preferential_origin_eligible']);
        });
        Schema::dropIfExists('customs_import_bills');
        Schema::dropIfExists('customs_declaration_invoices');
        Schema::table('customs_goods_declarations', function (Blueprint $table): void {
            $table->dropColumn(['customs_regime', 'transit_document_type', 'transit_guarantee_reference', 'transit_departure_office', 'transit_destination_office', 'transit_deadline_on', 'transit_discharged_at', 'border_crossing']);
        });
    }
};
