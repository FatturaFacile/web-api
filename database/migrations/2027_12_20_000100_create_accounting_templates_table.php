<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parametric journal templates (causali contabili) of the prima nota. A template with a null tenant_id is a global
 * system template shared by every tenant; a tenant adds its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('accounting_templates')) {
            return;
        }

        Schema::create('accounting_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('code', 50);
            $table->string('category', 30)->default('general');
            $table->string('journal_type', 20)->default('standard');
            $table->string('ledger_type', 20)->default('statutory');
            $table->json('names');
            $table->json('descriptions')->nullable();
            $table->json('parameters');
            $table->json('lines');
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code'], 'uq_accounting_template_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_templates');
    }
};
