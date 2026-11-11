<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disposal (scarto) of closed preservation packages after their retention, and the per-tenant RFC 3161 time-stamp
 * authority. A closed package is never updated by a disposal: the disposal is a separate append-only record, and only
 * the files on disk are deleted, so the immutability triggers of the package and its items need no exception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preservation_settings', function (Blueprint $table): void {
            $table->string('tsa_url', 2048)->nullable();
            $table->text('tsa_username')->nullable();
            $table->text('tsa_password')->nullable();
            $table->string('tsa_provider_name', 120)->nullable();
            $table->boolean('tsa_declared_qualified')->default(false);
        });

        Schema::create('preservation_package_holds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('package_id')->index();
            $table->string('reason', 500);
            $table->uuid('placed_by')->nullable();
            $table->timestamp('placed_at');
            $table->uuid('released_by')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'package_id']);
        });

        Schema::create('preservation_disposal_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('package_id')->index();
            $table->string('status', 12)->default('pending');
            $table->date('expiry_date');
            $table->string('note', 500)->nullable();
            $table->uuid('proposed_by');
            $table->timestamp('proposed_at');
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->uuid('executed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('preservation_disposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('package_id')->unique();
            $table->uuid('proposal_id');
            $table->string('document_class', 30);
            $table->string('package_name', 200);
            $table->unsignedInteger('items_count');
            $table->unsignedBigInteger('total_bytes');
            $table->date('retention_until');
            $table->date('expiry_date');
            $table->char('manifest_sha256', 64);
            $table->longText('manifest_json');
            $table->jsonb('files');
            $table->uuid('proposed_by');
            $table->uuid('approved_by');
            $table->uuid('executed_by');
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['tenant_id', 'executed_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_preservation_disposals_guard ON preservation_disposals;
                CREATE TRIGGER trg_preservation_disposals_guard BEFORE UPDATE OR DELETE ON preservation_disposals
                    FOR EACH ROW EXECUTE FUNCTION preservation_append_only_guard();
            ');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_preservation_disposals_guard ON preservation_disposals;');
        }

        Schema::dropIfExists('preservation_disposals');
        Schema::dropIfExists('preservation_disposal_proposals');
        Schema::dropIfExists('preservation_package_holds');

        Schema::table('preservation_settings', function (Blueprint $table): void {
            $table->dropColumn(['tsa_url', 'tsa_username', 'tsa_password', 'tsa_provider_name', 'tsa_declared_qualified']);
        });
    }
};
