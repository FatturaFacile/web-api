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
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('sdi_channel', 30)->nullable()->after('pec_email'); // pec, openapi, acube, invoicetronic, local
            $table->string('sdi_environment', 20)->default('production')->after('sdi_channel'); // production, sandbox
            $table->string('sdi_pec_smtp_host')->nullable();
            $table->unsignedInteger('sdi_pec_smtp_port')->nullable();
            $table->string('sdi_pec_imap_host')->nullable();
            $table->unsignedInteger('sdi_pec_imap_port')->nullable();
            $table->string('sdi_pec_username')->nullable();
            $table->text('sdi_pec_password')->nullable(); // encrypted
            $table->string('sdi_pec_address')->nullable(); // dedicated sdiNN@pec.fatturapa.it assigned by SDI
            $table->timestamp('sdi_pec_first_sent_at')->nullable();
            $table->unsignedBigInteger('sdi_pec_last_uid')->default(0);
            $table->text('sdi_api_credentials')->nullable(); // encrypted JSON
            $table->text('sdi_webhook_secret')->nullable(); // encrypted
            $table->boolean('sdi_sign_outgoing')->default(false);
            $table->unsignedBigInteger('sdi_file_counter')->default(0);
            $table->timestamp('sdi_last_poll_at')->nullable();
            $table->boolean('ade_telematic_address_registered')->default(false);
            $table->date('ade_conservation_agreement_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn([
                'sdi_channel', 'sdi_environment', 'sdi_pec_smtp_host', 'sdi_pec_smtp_port', 'sdi_pec_imap_host',
                'sdi_pec_imap_port', 'sdi_pec_username', 'sdi_pec_password', 'sdi_pec_address', 'sdi_pec_first_sent_at',
                'sdi_pec_last_uid', 'sdi_api_credentials', 'sdi_webhook_secret', 'sdi_sign_outgoing', 'sdi_file_counter',
                'sdi_last_poll_at', 'ade_telematic_address_registered', 'ade_conservation_agreement_at',
            ]);
        });
    }
};
