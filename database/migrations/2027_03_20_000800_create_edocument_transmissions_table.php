<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('edocument_transmissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->string('network', 20)->index();
            $table->string('document_reference', 190);
            $table->string('recipient_identifier', 190);
            $table->char('payload_sha256', 64);
            $table->string('status', 20)->index();
            $table->string('external_id', 190)->nullable();
            $table->string('driver', 60);
            $table->uuid('sent_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('edocument_transmissions');
    }
};
