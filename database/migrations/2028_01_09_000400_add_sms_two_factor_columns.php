<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The SMS second factor: the confirmed phone number of the factor (E.164, stored encrypted, so a text column) and
 * the moment it was confirmed. The profile phone of the user stays untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('two_factor_sms_phone')->nullable();
            $table->timestamp('two_factor_sms_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['two_factor_sms_phone', 'two_factor_sms_confirmed_at']);
        });
    }
};
