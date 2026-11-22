<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photo attachments of a field work order uploaded by the technician app. `client_uuid` makes the upload idempotent per
 * tenant; the signed service report references the photos by id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esm_work_order_photos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('work_order_id')->constrained('esm_work_orders')->cascadeOnDelete();
            $table->string('client_uuid', 64);
            $table->string('path', 255);
            $table->string('stage', 10)->nullable(); // before|during|after
            $table->string('caption', 255)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->uuid('uploaded_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->unique(['tenant_id', 'client_uuid'], 'esm_work_order_photos_tenant_client_uuid_unique');
            $table->index('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_work_order_photos');
    }
};
