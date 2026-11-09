<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Labour time of the technicians on a work folder, clocked on the job or entered afterwards, until it is billed as a
     * labour line of the folder.
     */
    public function up(): void
    {
        Schema::create('workshop_time_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('folder_id')->constrained('workshop_folders')->cascadeOnDelete();
            $table->foreignUuid('technician_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignUuid('folder_line_id')->nullable()->constrained('workshop_folder_lines')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('minutes')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'folder_id']);
            $table->index(['tenant_id', 'technician_user_id', 'ended_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_time_entries');
    }
};
