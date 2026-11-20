<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The method the payer picked on the portal (card, twint, sepa, paypal, qr) and the per-tenant allow-list of methods.
     */
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table): void {
            $table->string('payment_method', 20)->nullable()->after('provider');
        });

        Schema::table('payment_gateway_settings', function (Blueprint $table): void {
            $table->json('allowed_payment_methods')->nullable()->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateway_settings', function (Blueprint $table): void {
            $table->dropColumn('allowed_payment_methods');
        });

        Schema::table('payment_links', function (Blueprint $table): void {
            $table->dropColumn('payment_method');
        });
    }
};
