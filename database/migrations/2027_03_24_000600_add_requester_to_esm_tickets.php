<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal IT tickets: the colleague who asked for help. Additive, nullable, safe on a populated table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('esm_tickets', function (Blueprint $table): void {
            $table->foreignUuid('requester_user_id')->nullable()->after('contact_id')->constrained('users')->nullOnDelete();
            $table->index(['tenant_id', 'requester_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('esm_tickets', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'requester_user_id']);
            $table->dropConstrainedForeignId('requester_user_id');
        });
    }
};
