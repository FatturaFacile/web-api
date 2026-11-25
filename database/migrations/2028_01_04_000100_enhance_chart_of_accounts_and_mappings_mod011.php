<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 011 — Multi-standard Chart of Accounts (CoA): unlimited hierarchy depth, Swiss KMU/Käfer & Italian Civil Code/OIC
 * templates, accounting properties (VAT, cost center, open items, allowed currencies), and bidirectional mapping trees.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    public function up(): void
    {
        if (Schema::hasTable('chart_of_accounts')) {
            Schema::table('chart_of_accounts', function (Blueprint $table): void {
                if (! Schema::hasColumn('chart_of_accounts', 'description')) {
                    $table->text('description')->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'level')) {
                    $table->unsignedSmallInteger('level')->default(1);
                }
                if (! Schema::hasColumn('chart_of_accounts', 'hierarchy_path')) {
                    $table->string('hierarchy_path', 500)->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'vat_code_id')) {
                    $table->uuid('vat_code_id')->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'default_cost_center_id')) {
                    $table->uuid('default_cost_center_id')->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'open_items_enabled')) {
                    $table->boolean('open_items_enabled')->default(false);
                }
                if (! Schema::hasColumn('chart_of_accounts', 'allowed_currencies')) {
                    $table->json('allowed_currencies')->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'is_postable')) {
                    $table->boolean('is_postable')->default(true);
                }
                if (! Schema::hasColumn('chart_of_accounts', 'is_system')) {
                    $table->boolean('is_system')->default(false);
                }
                if (! Schema::hasColumn('chart_of_accounts', 'template_code')) {
                    $table->string('template_code', 80)->nullable();
                }
                if (! Schema::hasColumn('chart_of_accounts', 'statutory_code')) {
                    $table->string('statutory_code', 80)->nullable();
                }

                $table->index(['tenant_id', 'parent_account_id'], 'idx_coa_tenant_parent');
                $table->index(['tenant_id', 'standard', 'is_active'], 'idx_coa_tenant_standard');
                $table->index(['tenant_id', 'hierarchy_path'], 'idx_coa_tenant_path');
            });
        }

        if (! Schema::hasTable('gl_coa_templates')) {
            Schema::create('gl_coa_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('key', 60);
                $table->string('standard', 60);
                $table->string('country', 2);
                $table->string('currency', 3);
                $table->json('texts');
                $table->json('nodes');
                $table->json('default_mappings')->nullable();
                $table->boolean('is_active')->default(true);
                $this->audit($table);

                $table->unique(['tenant_id', 'key'], 'uq_gl_coa_templates_key');
                $table->index(['tenant_id', 'is_active'], 'idx_gl_coa_templates_active');
            });
        }

        if (! Schema::hasTable('gl_account_mappings')) {
            Schema::create('gl_account_mappings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->uuid('company_id')->nullable()->index('idx_gl_acc_map_company');
                $table->foreignUuid('source_account_id')->constrained('chart_of_accounts')->cascadeOnDelete();
                $table->foreignUuid('target_account_id')->nullable()->constrained('chart_of_accounts')->nullOnDelete();
                $table->string('source_standard', 60);
                $table->string('target_standard', 60);
                $table->string('target_account_code', 60);
                $table->string('target_account_name', 255);
                $table->string('mapping_type', 30)->default('direct');
                $table->boolean('is_bidirectional')->default(true);
                $table->decimal('allocation_percentage', 7, 4)->default(100.0000);
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $this->audit($table);

                $table->index(['tenant_id', 'source_account_id'], 'idx_gl_acc_map_source');
                $table->index(['tenant_id', 'target_account_id'], 'idx_gl_acc_map_target');
                $table->index(['tenant_id', 'source_standard', 'target_standard'], 'idx_gl_acc_map_standards');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('gl_account_mappings');
        Schema::dropIfExists('gl_coa_templates');

        if (Schema::hasTable('chart_of_accounts')) {
            Schema::table('chart_of_accounts', function (Blueprint $table): void {
                $table->dropIndex('idx_coa_tenant_parent');
                $table->dropIndex('idx_coa_tenant_standard');
                $table->dropIndex('idx_coa_tenant_path');
                $columns = array_values(array_filter([
                    'description', 'level', 'hierarchy_path', 'vat_code_id', 'default_cost_center_id',
                    'open_items_enabled', 'allowed_currencies', 'is_postable', 'is_system', 'template_code', 'statutory_code',
                ], static fn (string $col): bool => Schema::hasColumn('chart_of_accounts', $col)));

                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
