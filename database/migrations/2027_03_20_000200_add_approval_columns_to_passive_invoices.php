<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existing supplier invoices stay payable: the default `not_required` keeps them out of the approval process.
     */
    public function up(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->string('approval_status', 20)->default('not_required')->index();
            $table->uuid('cost_center_id')->nullable()->index();
            $table->uuid('approval_workflow_instance_id')->nullable();
            $table->uuid('approval_submitted_by')->nullable();
            $table->timestamp('approval_decided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('passive_invoices', function (Blueprint $table): void {
            $table->dropColumn(['approval_status', 'cost_center_id', 'approval_workflow_instance_id', 'approval_submitted_by', 'approval_decided_at']);
        });
    }
};
