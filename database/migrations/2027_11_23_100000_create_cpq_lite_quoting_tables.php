<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CPQ lite: revision grouping on quotes, configuration snapshots and optional lines on quote lines, tiered approval
 * thresholds with their decision requests, and tokenized e-signature requests with tamper-evident audit metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table): void {
            if (! Schema::hasColumn('quotes', 'quote_group_uuid')) {
                $table->uuid('quote_group_uuid')->nullable()->index();
            }

            if (! Schema::hasColumn('quotes', 'is_current_revision')) {
                $table->boolean('is_current_revision')->default(true);
            }

            if (! Schema::hasColumn('quotes', 'revision_notes')) {
                $table->text('revision_notes')->nullable();
            }

            if (! Schema::hasColumn('quotes', 'allow_optional_selection')) {
                $table->boolean('allow_optional_selection')->default(false);
            }
        });

        // Every existing quote starts its own revision group; revisions already linked share the group of their origin.
        DB::table('quotes')->whereNull('quote_group_uuid')->orderBy('id')->select('id')->each(static function (object $row): void {
            DB::table('quotes')->where('id', $row->id)->update(['quote_group_uuid' => $row->id]);
        });
        DB::table('quotes')->whereNotNull('revision_of_quote_id')->orderBy('id')->select(['id', 'revision_of_quote_id'])->each(static function (object $row): void {
            $origin = $row->revision_of_quote_id;

            for ($depth = 0; $depth < 100; $depth++) {
                $parent = DB::table('quotes')->where('id', $origin)->value('revision_of_quote_id');

                if ($parent === null) {
                    break;
                }

                $origin = $parent;
            }

            DB::table('quotes')->where('id', $row->id)->update(['quote_group_uuid' => $origin]);
        });
        DB::table('quotes')->where('status', 'superseded')->update(['is_current_revision' => false]);

        Schema::table('quote_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('quote_items', 'configuration_snapshot')) {
                $table->json('configuration_snapshot')->nullable();
            }

            if (! Schema::hasColumn('quote_items', 'labor_cost')) {
                $table->decimal('labor_cost', 15, 4)->default(0);
            }

            if (! Schema::hasColumn('quote_items', 'is_optional')) {
                $table->boolean('is_optional')->default(false);
            }

            if (! Schema::hasColumn('quote_items', 'is_selected')) {
                $table->boolean('is_selected')->default(true);
            }
        });

        Schema::create('quote_approval_tiers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('name', 100);
            $table->string('approver_role', 50);
            $table->decimal('max_line_discount_percent', 5, 2)->nullable();
            $table->decimal('max_global_discount_percent', 5, 2)->nullable();
            $table->decimal('min_margin_percent', 6, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'level']);
        });

        Schema::create('quote_approval_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->unsignedSmallInteger('level');
            $table->string('required_role', 50);
            $table->string('status', 20)->default('pending')->index();
            $table->json('triggers')->nullable();
            $table->foreignUuid('requested_by')->nullable()->index();
            $table->foreignUuid('approved_by')->nullable()->index();
            $table->foreignUuid('rejected_by')->nullable()->index();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['quote_id', 'level']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('quote_signature_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('quote_id')->constrained('quotes')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('locale', 5)->default('it');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->string('signer_name')->nullable();
            $table->string('signer_role')->nullable();
            $table->string('purchase_order_number', 100)->nullable();
            $table->string('signature_type', 10)->nullable();
            $table->longText('signature_data')->nullable();
            $table->json('selected_optional_item_ids')->nullable();
            $table->text('change_request')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('payload_hash', 64)->nullable();
            $table->string('pdf_hash', 64)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->foreignUuid('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_signature_requests');
        Schema::dropIfExists('quote_approval_requests');
        Schema::dropIfExists('quote_approval_tiers');

        Schema::table('quote_items', function (Blueprint $table): void {
            foreach (['configuration_snapshot', 'labor_cost', 'is_optional', 'is_selected'] as $column) {
                if (Schema::hasColumn('quote_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('quotes', function (Blueprint $table): void {
            foreach (['quote_group_uuid', 'is_current_revision', 'revision_notes', 'allow_optional_selection'] as $column) {
                if (Schema::hasColumn('quotes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
