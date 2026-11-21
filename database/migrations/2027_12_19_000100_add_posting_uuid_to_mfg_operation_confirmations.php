<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client-generated idempotency key of an operation confirmation: a retried posting (offline queue, edge gateway,
 * mobile terminal) returns the original confirmation instead of consuming materials twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('mfg_operation_confirmations', 'posting_uuid')) {
            return;
        }

        Schema::table('mfg_operation_confirmations', function (Blueprint $table): void {
            $table->uuid('posting_uuid')->nullable();
            $table->unique(['tenant_id', 'posting_uuid'], 'mfg_op_conf_posting_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('mfg_operation_confirmations', function (Blueprint $table): void {
            $table->dropUnique('mfg_op_conf_posting_uuid_unique');
            $table->dropColumn('posting_uuid');
        });
    }
};
