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
        Schema::create('legal_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('case_number', 50)->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('aml_kyc_completed')->default(false)->index(); // Anti-Money Laundering verification
            $table->string('status', 30)->default('open')->index(); // open, pending, closed, archived
            $table->enum('jurisdiction', ['IT', 'CH'])->default('IT');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'case_number']);
        });

        Schema::create('billable_activities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('duration_minutes');
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('fee_amount', 15, 2);
            $table->text('description')->nullable();
            $table->boolean('is_billed')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('fee_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->nullable()->constrained('legal_cases')->nullOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('note_number', 50)->index(); // Parcela number
            $table->date('date');
            $table->decimal('taxable_fees', 15, 2);
            $table->decimal('cassa_previdenza_amount', 15, 2)->default(0); // e.g. 4% in IT
            $table->decimal('ritenuta_acconto_amount', 15, 2)->default(0);  // e.g. 20% in IT
            $table->decimal('vat_amount', 15, 2)->default(0);
            $table->decimal('net_payable', 15, 2);
            $table->string('status', 30)->default('draft')->index(); // draft, issued, paid
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'note_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_notes');
        Schema::dropIfExists('billable_activities');
        Schema::dropIfExists('legal_cases');
    }
};
