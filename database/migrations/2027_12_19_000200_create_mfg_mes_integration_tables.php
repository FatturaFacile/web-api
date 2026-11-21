<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #178 integration tables: the WIP ledger, edge gateways with their HMAC secrets, idempotent machine state
 * events, the production strategy of an order (MTO, MTS, ATO) and the live state of a machine.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mfg_wip_transactions')) {
            Schema::create('mfg_wip_transactions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('production_order_id')->index();
                $table->string('transaction_type', 30)->index();
                $table->decimal('amount', 15, 4);
                $table->string('source_type', 60)->nullable();
                $table->uuid('source_id')->nullable();
                $table->uuid('journal_entry_id')->nullable();
                $table->date('posting_date');
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('production_order_id')->references('id')->on('mfg_production_orders')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('mfg_edge_gateways')) {
            Schema::create('mfg_edge_gateways', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->string('name', 120);
                $table->text('secret');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('mfg_machine_state_events')) {
            Schema::create('mfg_machine_state_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('tenant_id')->nullable()->index();
                $table->uuid('gateway_id')->index();
                $table->uuid('machine_id')->index();
                $table->uuid('event_uuid');
                $table->string('state', 20);
                $table->timestamp('occurred_at');
                $table->uuid('production_order_operation_id')->nullable()->index();
                $table->json('payload')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'event_uuid'], 'mfg_machine_state_events_uuid_unique');
            });
        }

        Schema::table('mfg_production_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('mfg_production_orders', 'production_strategy')) {
                $table->string('production_strategy', 3)->nullable();
            }
        });

        Schema::table('mfg_machine_centers', function (Blueprint $table): void {
            if (! Schema::hasColumn('mfg_machine_centers', 'current_state')) {
                $table->string('current_state', 20)->nullable();
                $table->timestamp('state_since')->nullable();
            }
        });

        foreach (['mfg_wip_transactions', 'mfg_edge_gateways', 'mfg_machine_state_events'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                foreach (['created_by', 'updated_by', 'deleted_by'] as $column) {
                    if (! Schema::hasColumn($name, $column)) {
                        $table->uuid($column)->nullable();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('mfg_machine_centers', function (Blueprint $table): void {
            $table->dropColumn(['current_state', 'state_since']);
        });
        Schema::table('mfg_production_orders', function (Blueprint $table): void {
            $table->dropColumn('production_strategy');
        });
        Schema::dropIfExists('mfg_machine_state_events');
        Schema::dropIfExists('mfg_edge_gateways');
        Schema::dropIfExists('mfg_wip_transactions');
    }
};
