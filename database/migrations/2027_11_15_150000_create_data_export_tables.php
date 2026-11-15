<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An export job is the history row of one export (queued, running, completed or failed) with the stored file, an
     * export schedule creates the jobs by itself on a daily, weekly or monthly cadence.
     */
    public function up(): void
    {
        Schema::create('export_schedules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->index();
            $table->string('dataset', 30);
            $table->string('frequency', 10);
            $table->string('period', 20)->default('all');
            $table->boolean('is_active')->default(true);
            $table->date('next_run_on')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->nullable()->index();
            $table->uuid('schedule_id')->nullable()->index();
            $table->string('dataset', 30);
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('status', 15)->default('queued')->index();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('file_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('export_schedules');
    }
};
