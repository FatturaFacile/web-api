<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satisfaction surveys sent when a ticket is resolved: a survey exists before the customer answers, so customer, score
 * and answer time become optional, and the secret link is stored as a hash. The respondent and status columns the CSAT
 * endpoint already writes are added. Dropping NOT NULL and adding nullable or defaulted columns is safe on a populated
 * table; existing rows are completed answers and keep their values.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE esm_csat_surveys ALTER COLUMN customer_id DROP NOT NULL');
            DB::statement('ALTER TABLE esm_csat_surveys ALTER COLUMN responded_at DROP NOT NULL');
            DB::statement('ALTER TABLE esm_csat_surveys ALTER COLUMN score DROP NOT NULL');
        } else {
            Schema::table('esm_csat_surveys', function (Blueprint $table): void {
                $table->uuid('customer_id')->nullable()->change();
                $table->timestamp('responded_at')->nullable()->change();
                $table->unsignedTinyInteger('score')->nullable()->change();
            });
        }

        Schema::table('esm_csat_surveys', function (Blueprint $table): void {
            if (! Schema::hasColumn('esm_csat_surveys', 'respondent_email')) {
                $table->string('respondent_email', 180)->nullable();
            }

            if (! Schema::hasColumn('esm_csat_surveys', 'status')) {
                $table->string('status', 20)->default('completed');
            }

            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::table('esm_csat_surveys', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropIndex(['tenant_id', 'ticket_id']);
            $table->dropUnique(['token_hash']);
            $table->dropColumn(['token_hash', 'sent_at', 'expires_at', 'respondent_email', 'status']);
        });
    }
};
