<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail of the intra-Community VAT checks (VIES): the consultation identifier is the proof the tax
     * authority may ask for when an intra-EU supply is exempted.
     */
    public function up(): void
    {
        Schema::create('vat_validation_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('contact_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('country_code', 2);
            $table->string('vat_number', 20);
            $table->string('status', 15);
            $table->boolean('is_valid')->nullable();
            $table->string('trader_name')->nullable();
            $table->text('trader_address')->nullable();
            $table->string('request_identifier', 60)->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('validated_at');
            $table->timestamps();

            $table->index(['tenant_id', 'country_code', 'vat_number']);
            $table->index(['tenant_id', 'contact_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_validation_logs');
    }
};
