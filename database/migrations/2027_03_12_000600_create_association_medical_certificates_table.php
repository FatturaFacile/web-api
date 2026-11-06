<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Medical certificates per person and discipline. The scan stays on the private disk; only the dates that the
     * expiry lists and the access checks need are in clear.
     */
    public function up(): void
    {
        Schema::create('assoc_medical_certificates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->foreignUuid('discipline_id')->nullable()->constrained('assoc_disciplines')->nullOnDelete();
            $table->string('kind', 20);
            $table->date('issued_on')->nullable();
            $table->date('expires_on');
            $table->text('physician')->nullable();
            $table->text('notes')->nullable();
            $table->string('disk', 30)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('original_name')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'expires_on']);
            $table->index(['member_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_medical_certificates');
    }
};
