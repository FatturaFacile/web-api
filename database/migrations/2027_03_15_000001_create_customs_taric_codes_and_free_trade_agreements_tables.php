<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customs_taric_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code', 10)->unique();
            $table->string('level', 10)->index(); // HS6 | CN8 | TARIC10
            $table->string('chapter', 2)->index();
            $table->json('description');
            $table->string('supplementary_unit')->nullable();
            $table->text('duty_rate_notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable()->index();
            $table->uuid('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('free_trade_agreements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->json('name');
            $table->json('partner_countries');
            $table->decimal('value_threshold_eur', 12, 2)->nullable();
            $table->decimal('value_threshold_chf', 12, 2)->nullable();
            $table->json('proof_types');
            $table->boolean('supports_approved_exporter')->default(false);
            $table->boolean('supports_rex')->default(false);
            $table->json('declaration_templates');
            $table->json('declaration_templates_no_auth');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->uuid('created_by')->nullable()->index();
            $table->uuid('updated_by')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignUuid('customs_taric_code_id')->nullable()->constrained('customs_taric_codes')->nullOnDelete();
            $table->string('taric_code', 10)->nullable()->index();
            $table->string('country_of_origin', 2)->nullable();
            $table->string('origin_type', 20)->nullable();
            $table->string('made_in_label')->nullable();
            $table->foreignUuid('preferential_agreement_id')->nullable()->constrained('free_trade_agreements')->nullOnDelete();
            $table->boolean('eur1_required')->default(false);
            $table->text('origin_rule_notes')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('customs_approved_exporter_number', 50)->nullable();
            $table->string('rex_exporter_number', 50)->nullable();
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->string('taric_code', 10)->nullable();
            $table->string('country_of_origin', 2)->nullable();
            $table->string('origin_type', 20)->nullable();
            $table->string('made_in_label')->nullable();
        });

        Schema::table('invoices', function (Blueprint $table): void {
            $table->foreignUuid('free_trade_agreement_id')->nullable()->constrained('free_trade_agreements')->nullOnDelete();
            $table->text('origin_declaration_text')->nullable();
            $table->boolean('duty_exemption_eligible')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('free_trade_agreement_id');
            $table->dropColumn(['origin_declaration_text', 'duty_exemption_eligible']);
        });

        Schema::table('invoice_items', function (Blueprint $table): void {
            $table->dropColumn(['taric_code', 'country_of_origin', 'origin_type', 'made_in_label']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn(['customs_approved_exporter_number', 'rex_exporter_number']);
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('customs_taric_code_id');
            $table->dropConstrainedForeignId('preferential_agreement_id');
            $table->dropColumn(['taric_code', 'country_of_origin', 'origin_type', 'made_in_label', 'eur1_required', 'origin_rule_notes']);
        });

        Schema::dropIfExists('free_trade_agreements');
        Schema::dropIfExists('customs_taric_codes');
    }
};
