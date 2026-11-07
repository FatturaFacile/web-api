<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transmission is claimed before the file leaves, so a second request cannot send it again. Inbound messages that
     * cannot be read or trusted are kept whole in the inbound log (quarantine) instead of being skipped.
     */
    public function up(): void
    {
        Schema::table('invoice_sdi_metadata', function (Blueprint $table): void {
            $table->timestamp('transmission_claimed_at')->nullable();
        });

        Schema::table('ei_inbound_logs', function (Blueprint $table): void {
            $table->string('source_reference')->nullable();
            $table->longText('raw_content')->nullable(); // base64 of the original message, kept for a retry
            $table->boolean('signature_verified')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('ei_inbound_logs', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropColumn(['source_reference', 'raw_content', 'signature_verified', 'attempts']);
        });

        Schema::table('invoice_sdi_metadata', function (Blueprint $table): void {
            $table->dropColumn('transmission_claimed_at');
        });
    }
};
