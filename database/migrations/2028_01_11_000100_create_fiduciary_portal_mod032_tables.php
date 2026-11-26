<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 032 — External Accountant & Fiduciary Portal (Issue #336).
 *
 * Dialect-agnostic schema (100% SQLite & PostgreSQL compatible).
 */
return new class extends Migration
{
    private function auditColumns(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    public function up(): void
    {
        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                if (! Schema::hasColumn('journal_entries', 'fiduciary_verified')) {
                    $table->boolean('fiduciary_verified')->default(false)->index('idx_je_fiduciary_verified');
                }
                if (! Schema::hasColumn('journal_entries', 'fiduciary_verified_at')) {
                    $table->timestamp('fiduciary_verified_at')->nullable();
                }
                if (! Schema::hasColumn('journal_entries', 'fiduciary_verified_by')) {
                    $table->uuid('fiduciary_verified_by')->nullable();
                }
                if (! Schema::hasColumn('journal_entries', 'fiduciary_verification_note')) {
                    $table->text('fiduciary_verification_note')->nullable();
                }
            });
        }

        if (Schema::hasTable('fiscal_periods')) {
            Schema::table('fiscal_periods', function (Blueprint $table): void {
                if (! Schema::hasColumn('fiscal_periods', 'fiduciary_locked_at')) {
                    $table->timestamp('fiduciary_locked_at')->nullable();
                }
                if (! Schema::hasColumn('fiscal_periods', 'fiduciary_locked_by')) {
                    $table->uuid('fiduciary_locked_by')->nullable();
                }
                if (! Schema::hasColumn('fiscal_periods', 'fiduciary_lock_reason')) {
                    $table->text('fiduciary_lock_reason')->nullable();
                }
            });
        }

        if (! Schema::hasTable('fiduciary_account_transcodings')) {
            Schema::create('fiduciary_account_transcodings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('practitioner_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('studio_system', 40)->default('universal')->index();
                $table->foreignUuid('source_account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
                $table->string('source_account_code', 60);
                $table->string('source_account_name', 255);
                $table->string('source_standard', 60)->default('it_oic');
                $table->string('studio_account_code', 60);
                $table->string('studio_account_name', 255);
                $table->string('studio_vat_code', 40)->nullable();
                $table->string('studio_cost_center_code', 60)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $this->auditColumns($table);

                $table->unique(['tenant_id', 'studio_system', 'source_account_id'], 'uq_fiduciary_transcoding_account');
                $table->index(['tenant_id', 'studio_system', 'is_active'], 'idx_fid_transcoding_tenant_system');
            });
        }

        if (! Schema::hasTable('fiduciary_entry_notes')) {
            Schema::create('fiduciary_entry_notes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->foreignUuid('journal_entry_id')->constrained('journal_entries')->cascadeOnDelete();
                $table->uuid('parent_note_id')->nullable()->index();
                $table->foreignUuid('author_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('author_name', 160);
                $table->string('author_role', 60)->default('fiduciary');
                $table->text('body');
                $table->string('status', 30)->default('open')->index();
                $table->timestamp('resolved_at')->nullable();
                $table->uuid('resolved_by')->nullable();
                $table->text('resolution_comment')->nullable();
                $this->auditColumns($table);

                $table->index(['tenant_id', 'journal_entry_id', 'status'], 'idx_fid_notes_tenant_entry_status');
            });
        }

        if (! Schema::hasTable('fiduciary_export_runs')) {
            Schema::create('fiduciary_export_runs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('fiscal_year_id')->nullable()->index();
                $table->uuid('fiscal_period_id')->nullable()->index();
                $table->string('format_code', 50)->index();
                $table->string('jurisdiction', 10)->default('ALL');
                $table->date('from_date');
                $table->date('to_date');
                $table->boolean('only_verified')->default(false);
                $table->boolean('apply_transcoding')->default(true);
                $table->unsignedInteger('entries_count')->default(0);
                $table->unsignedInteger('lines_count')->default(0);
                $table->unsignedInteger('unmapped_accounts_count')->default(0);
                $table->decimal('total_debit', 18, 4)->default(0);
                $table->decimal('total_credit', 18, 4)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->string('file_name', 255);
                $table->string('mime_type', 120);
                $table->longText('content_payload')->nullable();
                $table->json('diagnostics_json')->nullable();
                $table->uuid('exported_by')->nullable();
                $this->auditColumns($table);

                $table->index(['tenant_id', 'format_code', 'created_at'], 'idx_fid_exports_tenant_format');
            });
        }

        if (! Schema::hasTable('fiduciary_audit_logs')) {
            Schema::create('fiduciary_audit_logs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->index()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index();
                $table->uuid('actor_user_id')->nullable()->index();
                $table->string('actor_name', 160)->nullable();
                $table->string('actor_role', 60)->nullable();
                $table->string('action', 80)->index();
                $table->string('target_type', 80)->nullable();
                $table->uuid('target_id')->nullable()->index();
                $table->string('target_reference', 160)->nullable();
                $table->string('summary', 500);
                $table->json('metadata_json')->nullable();
                $table->string('ip_address', 45)->nullable();
                $this->auditColumns($table);

                $table->index(['tenant_id', 'action', 'created_at'], 'idx_fid_audit_tenant_action');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiduciary_audit_logs');
        Schema::dropIfExists('fiduciary_export_runs');
        Schema::dropIfExists('fiduciary_entry_notes');
        Schema::dropIfExists('fiduciary_account_transcodings');

        if (Schema::hasTable('fiscal_periods')) {
            Schema::table('fiscal_periods', function (Blueprint $table): void {
                $columns = array_values(array_filter([
                    'fiduciary_locked_at',
                    'fiduciary_locked_by',
                    'fiduciary_lock_reason',
                ], static fn (string $col): bool => Schema::hasColumn('fiscal_periods', $col)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }

        if (Schema::hasTable('journal_entries')) {
            Schema::table('journal_entries', function (Blueprint $table): void {
                if (Schema::hasColumn('journal_entries', 'fiduciary_verified')) {
                    $table->dropIndex('idx_je_fiduciary_verified');
                }
                $columns = array_values(array_filter([
                    'fiduciary_verified',
                    'fiduciary_verified_at',
                    'fiduciary_verified_by',
                    'fiduciary_verification_note',
                ], static fn (string $col): bool => Schema::hasColumn('journal_entries', $col)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
