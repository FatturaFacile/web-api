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
        Schema::create('time_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('clock_in')->index();
            $table->dateTime('clock_out')->nullable()->index();
            $table->decimal('clock_in_lat', 10, 7)->nullable();
            $table->decimal('clock_in_lng', 10, 7)->nullable();
            $table->boolean('is_simplified_record')->default(false); // Swiss Labour Act (ArG Art. 73b OLL 1) simplified recording
            $table->unsignedInteger('break_minutes')->default(0);
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'user_id', 'clock_in']);
        });

        Schema::create('leave_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('type', ['vacation', 'rol', 'sick_inps', 'ktg_ch', 'military_ch_apg'])->index();
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('hours', 6, 2);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->string('medical_cert_protocol', 100)->nullable(); // INPS certificato medico or Swiss Arztzeugnis
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('leave_balances', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('year')->index();
            $table->decimal('vacation_accrued', 6, 2)->default(0);
            $table->decimal('vacation_taken', 6, 2)->default(0);
            $table->decimal('rol_accrued', 6, 2)->default(0);      // Riduzione Orario di Lavoro (IT)
            $table->decimal('rol_taken', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'user_id', 'year']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('time_entries');
    }
};
