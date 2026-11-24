<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receivable_provision_postings')) {
            return;
        }

        Schema::create('receivable_provision_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->date('as_of');
            $table->string('currency', 3);
            // Provision required at `as_of`, the provision already booked before this posting and the difference booked now.
            $table->decimal('total_provision', 15, 2);
            $table->decimal('previous_provision', 15, 2);
            $table->decimal('adjustment_amount', 15, 2);
            $table->uuid('journal_entry_id')->nullable()->index();
            $table->json('breakdown')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();

            $table->index(['tenant_id', 'currency', 'as_of']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivable_provision_postings');
    }
};
