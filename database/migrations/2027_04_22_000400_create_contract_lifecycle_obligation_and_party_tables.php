<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lifecycle history, obligations/milestones and linked parties of the contracts of the register. A contract is
     * identified by its kind (`customer` or `supplier`) and its id, as in `contract_alerts` and `contract_renewals`: the
     * register still adds no third contract table.
     */
    public function up(): void
    {
        if (! Schema::hasTable('contract_status_changes')) {
            Schema::create('contract_status_changes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('contract_kind', 20);
                $table->uuid('contract_id');
                $table->string('from_status', 30)->nullable();
                $table->string('to_status', 30);
                $table->text('reason')->nullable();
                $table->uuid('changed_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'contract_kind', 'contract_id', 'created_at'], 'idx_contract_status_changes_contract');
            });
        }

        if (! Schema::hasTable('contract_obligations')) {
            Schema::create('contract_obligations', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('contract_kind', 20);
                $table->uuid('contract_id');
                $table->string('type', 20)->default('obligation');
                $table->string('title', 255);
                $table->text('description')->nullable();
                $table->string('responsible_party', 20)->default('own');
                $table->uuid('owner_user_id')->nullable()->index();
                $table->date('due_date');
                $table->decimal('amount', 15, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->string('status', 20)->default('open');
                $table->unsignedSmallInteger('remind_days_before')->default(7);
                $table->timestampTz('reminded_at')->nullable();
                $table->timestampTz('completed_at')->nullable();
                $table->uuid('completed_by')->nullable();
                $table->text('completion_note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'contract_kind', 'contract_id'], 'idx_contract_obligations_contract');
                $table->index(['tenant_id', 'status', 'due_date'], 'idx_contract_obligations_due');
            });
        }

        if (! Schema::hasTable('contract_parties')) {
            Schema::create('contract_parties', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->index();
                $table->string('contract_kind', 20);
                $table->uuid('contract_id');
                $table->string('party_type', 20);
                $table->uuid('party_id')->nullable();
                $table->string('name', 255)->nullable();
                $table->string('role', 40);
                $table->string('email', 255)->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'contract_kind', 'contract_id'], 'idx_contract_parties_contract');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_parties');
        Schema::dropIfExists('contract_obligations');
        Schema::dropIfExists('contract_status_changes');
    }
};
