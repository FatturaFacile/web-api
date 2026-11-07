<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reclassification schemes are written by the users: the application ships no statutory layout. The class on the
     * account drives the cash flow statement and the ratios, and an account without one is reported, never hidden.
     */
    public function up(): void
    {
        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->string('reporting_class', 30)->nullable();
        });

        Schema::create('gl_statement_schemes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('name', 190);
            $table->string('statement_type', 30);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('gl_statement_scheme_rows', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('scheme_id')->index();
            $table->string('code', 40);
            $table->string('label', 255);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('row_type', 20);
            $table->smallInteger('sign')->default(1);
            $table->jsonb('account_prefixes')->default('[]');
            $table->jsonb('formula')->default('[]');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('scheme_id')->references('id')->on('gl_statement_schemes')->cascadeOnDelete();
            $table->unique(['scheme_id', 'code'], 'uq_gl_scheme_row_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_statement_scheme_rows');
        Schema::dropIfExists('gl_statement_schemes');

        Schema::table('chart_of_accounts', function (Blueprint $table): void {
            $table->dropColumn('reporting_class');
        });
    }
};
