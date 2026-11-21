<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos taken with the phone on the spot of a near-miss or an accident (Mod 099). The file lives on the private disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hse_incident_photos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('incident_report_id')->constrained('hse_incident_reports')->cascadeOnDelete();
            $table->string('file_path', 255);
            $table->string('mime_type', 32);
            $table->unsignedInteger('size_bytes');
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'incident_report_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hse_incident_photos');
    }
};
