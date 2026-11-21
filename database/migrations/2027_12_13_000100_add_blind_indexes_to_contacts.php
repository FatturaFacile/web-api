<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            // HMAC-SHA256 blind index of the encrypted IBAN: exact-match search without decrypting the table.
            $table->string('iban_bidx', 64)->nullable()->index();
            $table->string('qr_iban_bidx', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['iban_bidx']);
            $table->dropIndex(['qr_iban_bidx']);
            $table->dropColumn(['iban_bidx', 'qr_iban_bidx']);
        });
    }
};
