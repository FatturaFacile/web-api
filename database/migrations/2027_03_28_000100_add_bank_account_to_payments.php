<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Treasury bank account a collection reached, when the flow that recorded it (a Ri.Ba. receipt) moved its balance.
     * Existing payments stay without one and keep posting as before.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignUuid('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
