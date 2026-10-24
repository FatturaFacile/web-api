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
        Schema::create('employee_profiles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_cross_border_commuter')->default(false)->index(); // Frontaliere IT <-> CH
            $table->string('social_security_number', 50)->nullable()->index();     // Codice Fiscale or Swiss AHV/AVS (756.xxxx.xxxx.xx)
            $table->decimal('swiss_cantonal_tax_rate', 5, 2)->nullable();         // Imposta alla fonte (TI, GR, VS, etc.)
            $table->string('inps_code', 50)->nullable();                          // IT INPS Matricola
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->date('hire_date')->nullable();
            $table->date('termination_date')->nullable();
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->string('currency', 3)->default('CHF');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
