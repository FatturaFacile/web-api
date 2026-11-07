<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Safety data sheets per product and language, every revision kept, and the log of what went out with which
     * delivery. Hazard classes are stored as entered from the supplier's sheet.
     */
    public function up(): void
    {
        Schema::create('safety_data_sheets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('product_id')->index();
            $table->string('language', 5);
            $table->string('title', 200);
            $table->uuid('current_version_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['tenant_id', 'product_id', 'language'], 'uq_safety_data_sheet');
        });

        Schema::create('safety_data_sheet_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('safety_data_sheet_id')->index();
            $table->integer('version_number');
            $table->date('revision_date');
            $table->jsonb('hazard_classes');
            $table->string('signal_word', 50)->nullable();
            $table->text('notes')->nullable();
            $table->string('disk', 50);
            $table->string('path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('safety_data_sheet_id')->references('id')->on('safety_data_sheets')->cascadeOnDelete();
            $table->unique(['safety_data_sheet_id', 'version_number'], 'uq_safety_data_sheet_version');
        });

        Schema::create('safety_data_sheet_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('delivery_note_id')->index();
            $table->uuid('safety_data_sheet_version_id');
            $table->uuid('contact_id');
            $table->string('email', 255)->nullable();
            $table->string('status', 20);
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('delivery_note_id')->references('id')->on('delivery_notes')->cascadeOnDelete();
            $table->foreign('safety_data_sheet_version_id')->references('id')->on('safety_data_sheet_versions')->restrictOnDelete();
            $table->unique(['delivery_note_id', 'safety_data_sheet_version_id'], 'uq_safety_data_sheet_delivery');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_data_sheet_deliveries');
        Schema::dropIfExists('safety_data_sheet_versions');
        Schema::dropIfExists('safety_data_sheets');
    }
};
