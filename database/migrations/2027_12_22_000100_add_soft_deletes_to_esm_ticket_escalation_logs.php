<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('esm_ticket_escalation_logs', 'deleted_at')) {
            return;
        }

        Schema::table('esm_ticket_escalation_logs', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('esm_ticket_escalation_logs', 'deleted_at')) {
            return;
        }

        Schema::table('esm_ticket_escalation_logs', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
