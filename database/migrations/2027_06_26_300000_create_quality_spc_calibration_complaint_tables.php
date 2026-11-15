<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quality extension: statistical process control (characteristics and subgroup samples), measuring instruments with
     * their calibrations, and customer complaints. Rules (unique codes, statuses, links) are enforced by the application,
     * not by the engine. Documents and users are referenced by id only.
     */
    public function up(): void
    {
        if (! Schema::hasTable('qms_instruments')) {
            Schema::create('qms_instruments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 190);
                $table->string('serial_number', 80)->nullable();
                $table->string('manufacturer', 120)->nullable();
                $table->string('location', 120)->nullable();
                $table->string('unit', 20)->nullable();
                $table->decimal('range_min', 14, 5)->nullable();
                $table->decimal('range_max', 14, 5)->nullable();
                $table->decimal('tolerance', 14, 5)->nullable();
                $table->unsignedSmallInteger('calibration_interval_days')->default(365);
                $table->string('status', 20)->default('active');
                $table->date('last_calibrated_on')->nullable();
                $table->date('next_due_on')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'next_due_on']);
            });
        }

        if (! Schema::hasTable('qms_calibrations')) {
            Schema::create('qms_calibrations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('instrument_id')->constrained('qms_instruments')->cascadeOnDelete();
                $table->date('calibrated_on');
                $table->date('next_due_on');
                $table->string('result', 20);
                $table->decimal('as_found_error', 14, 5)->nullable();
                $table->decimal('as_left_error', 14, 5)->nullable();
                $table->string('laboratory', 160)->nullable();
                $table->string('certificate_number', 80)->nullable();
                $table->uuid('certificate_document_id')->nullable();
                $table->uuid('performed_by')->nullable();
                $table->uuid('nonconformity_id')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'instrument_id', 'calibrated_on']);
            });
        }

        if (! Schema::hasTable('qms_spc_characteristics')) {
            Schema::create('qms_spc_characteristics', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 190);
                $table->string('unit', 20)->nullable();
                $table->decimal('lower_spec_limit', 14, 5)->nullable();
                $table->decimal('upper_spec_limit', 14, 5)->nullable();
                $table->decimal('target', 14, 5)->nullable();
                $table->unsignedTinyInteger('subgroup_size')->default(5);
                $table->uuid('instrument_id')->nullable();
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('qms_spc_samples')) {
            Schema::create('qms_spc_samples', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('characteristic_id')->constrained('qms_spc_characteristics')->cascadeOnDelete();
                $table->dateTime('sampled_at');
                $table->string('lot', 80)->nullable();
                $table->json('measurements');
                $table->decimal('mean', 14, 5);
                $table->decimal('range', 14, 5);
                $table->uuid('instrument_id')->nullable();
                $table->uuid('recorded_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'characteristic_id', 'sampled_at']);
                $table->index(['tenant_id', 'instrument_id']);
            });
        }

        if (! Schema::hasTable('qms_complaints')) {
            Schema::create('qms_complaints', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('number', 30);
                $table->string('customer_name', 190);
                $table->uuid('contact_id')->nullable();
                $table->date('received_at');
                $table->string('channel', 20)->default('email');
                $table->string('reference', 120)->nullable();
                $table->string('product_description', 190)->nullable();
                $table->text('description');
                $table->string('severity', 20)->default('minor');
                $table->string('status', 20)->default('received');
                $table->uuid('owner_id')->nullable();
                $table->text('root_cause')->nullable();
                $table->uuid('nonconformity_id')->nullable();
                $table->uuid('capa_action_id')->nullable();
                $table->text('reply_text')->nullable();
                $table->timestamp('replied_at')->nullable();
                $table->uuid('replied_by')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->uuid('closed_by')->nullable();
                $table->text('closure_notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'number']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'received_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qms_complaints');
        Schema::dropIfExists('qms_spc_samples');
        Schema::dropIfExists('qms_spc_characteristics');
        Schema::dropIfExists('qms_calibrations');
        Schema::dropIfExists('qms_instruments');
    }
};
