<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 022: Competence period dates (competence_start_date / competence_end_date) on sales invoice items,
 * passive invoice lines, and journal lines, plus source tracking and reversal linking on gl_accruals.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('invoice_items', 'competence_start_date')) {
                    $table->date('competence_start_date')->nullable();
                }
                if (! Schema::hasColumn('invoice_items', 'competence_end_date')) {
                    $table->date('competence_end_date')->nullable();
                }
            });

            Schema::table('invoice_items', function (Blueprint $table): void {
                if (! Schema::hasIndex('invoice_items', 'idx_invoice_items_competence_dates')) {
                    $table->index(['competence_start_date', 'competence_end_date'], 'idx_invoice_items_competence_dates');
                }
            });
        }

        if (Schema::hasTable('passive_invoice_lines')) {
            Schema::table('passive_invoice_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('passive_invoice_lines', 'competence_start_date')) {
                    $table->date('competence_start_date')->nullable();
                }
                if (! Schema::hasColumn('passive_invoice_lines', 'competence_end_date')) {
                    $table->date('competence_end_date')->nullable();
                }
            });

            Schema::table('passive_invoice_lines', function (Blueprint $table): void {
                if (! Schema::hasIndex('passive_invoice_lines', 'idx_passive_invoice_lines_competence')) {
                    $table->index(['tenant_id', 'competence_start_date', 'competence_end_date'], 'idx_passive_invoice_lines_competence');
                }
            });
        }

        if (Schema::hasTable('journal_lines')) {
            Schema::table('journal_lines', function (Blueprint $table): void {
                if (! Schema::hasColumn('journal_lines', 'competence_start_date')) {
                    $table->date('competence_start_date')->nullable();
                }
                if (! Schema::hasColumn('journal_lines', 'competence_end_date')) {
                    $table->date('competence_end_date')->nullable();
                }
            });

            Schema::table('journal_lines', function (Blueprint $table): void {
                if (! Schema::hasIndex('journal_lines', 'idx_journal_lines_competence')) {
                    $table->index(['tenant_id', 'competence_start_date', 'competence_end_date'], 'idx_journal_lines_competence');
                }
            });
        }

        if (Schema::hasTable('gl_accruals')) {
            Schema::table('gl_accruals', function (Blueprint $table): void {
                if (! Schema::hasColumn('gl_accruals', 'fiscal_year_id')) {
                    $table->uuid('fiscal_year_id')->nullable()->index();
                }
                if (! Schema::hasColumn('gl_accruals', 'source_type')) {
                    $table->string('source_type', 40)->nullable()->default('manual');
                }
                if (! Schema::hasColumn('gl_accruals', 'source_document_type')) {
                    $table->string('source_document_type', 40)->nullable();
                }
                if (! Schema::hasColumn('gl_accruals', 'source_document_id')) {
                    $table->uuid('source_document_id')->nullable()->index();
                }
                if (! Schema::hasColumn('gl_accruals', 'source_line_id')) {
                    $table->uuid('source_line_id')->nullable()->index();
                }
                if (! Schema::hasColumn('gl_accruals', 'source_document_number')) {
                    $table->string('source_document_number', 120)->nullable();
                }
                if (! Schema::hasColumn('gl_accruals', 'currency')) {
                    $table->string('currency', 3)->nullable();
                }
                if (! Schema::hasColumn('gl_accruals', 'period_days')) {
                    $table->unsignedInteger('period_days')->nullable();
                }
                if (! Schema::hasColumn('gl_accruals', 'recognized_amount')) {
                    $table->decimal('recognized_amount', 15, 4)->nullable();
                }
                if (! Schema::hasColumn('gl_accruals', 'reversal_journal_entry_id')) {
                    $table->uuid('reversal_journal_entry_id')->nullable()->index();
                }
            });

            Schema::table('gl_accruals', function (Blueprint $table): void {
                if (! Schema::hasIndex('gl_accruals', 'idx_gl_accruals_source_cutoff')) {
                    $table->index(['tenant_id', 'source_type', 'source_line_id', 'cutoff_date'], 'idx_gl_accruals_source_cutoff');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('gl_accruals')) {
            Schema::table('gl_accruals', function (Blueprint $table): void {
                if (Schema::hasIndex('gl_accruals', 'idx_gl_accruals_source_cutoff')) {
                    $table->dropIndex('idx_gl_accruals_source_cutoff');
                }
                foreach ([
                    'fiscal_year_id',
                    'source_type',
                    'source_document_type',
                    'source_document_id',
                    'source_line_id',
                    'source_document_number',
                    'currency',
                    'period_days',
                    'recognized_amount',
                    'reversal_journal_entry_id',
                ] as $column) {
                    if (Schema::hasColumn('gl_accruals', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('journal_lines')) {
            Schema::table('journal_lines', function (Blueprint $table): void {
                if (Schema::hasIndex('journal_lines', 'idx_journal_lines_competence')) {
                    $table->dropIndex('idx_journal_lines_competence');
                }
                foreach (['competence_start_date', 'competence_end_date'] as $column) {
                    if (Schema::hasColumn('journal_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('passive_invoice_lines')) {
            Schema::table('passive_invoice_lines', function (Blueprint $table): void {
                if (Schema::hasIndex('passive_invoice_lines', 'idx_passive_invoice_lines_competence')) {
                    $table->dropIndex('idx_passive_invoice_lines_competence');
                }
                foreach (['competence_start_date', 'competence_end_date'] as $column) {
                    if (Schema::hasColumn('passive_invoice_lines', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('invoice_items')) {
            Schema::table('invoice_items', function (Blueprint $table): void {
                if (Schema::hasIndex('invoice_items', 'idx_invoice_items_competence_dates')) {
                    $table->dropIndex('idx_invoice_items_competence_dates');
                }
                foreach (['competence_start_date', 'competence_end_date'] as $column) {
                    if (Schema::hasColumn('invoice_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
