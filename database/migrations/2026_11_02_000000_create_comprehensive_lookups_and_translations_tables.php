<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Countries & Country Translations
        if (! Schema::hasTable('countries')) {
            Schema::create('countries', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('iso_code_2', 2)->unique();
                $table->string('iso_code_3', 3)->unique();
                $table->string('numeric_code', 3)->unique();
                $table->string('phone_prefix', 10);
                $table->boolean('is_eu_member')->default(false);
                $table->boolean('is_sepa_member')->default(false);
                $table->string('currency_code', 3);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('country_translations')) {
            Schema::create('country_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('country_id')->constrained('countries')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->string('nationality', 150)->nullable();
                $table->timestamps();

                $table->unique(['country_id', 'locale']);
                $table->index('locale');
            });
        }

        // 2. Regions & Region Translations
        if (! Schema::hasTable('regions')) {
            Schema::create('regions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('country_id')->constrained('countries')->cascadeOnDelete();
                $table->string('code', 10);
                $table->string('type', 30)->default('province'); // province, canton, department, state
                $table->timestamps();

                $table->unique(['country_id', 'code']);
            });
        }

        if (! Schema::hasTable('region_translations')) {
            Schema::create('region_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('region_id')->constrained('regions')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->timestamps();

                $table->unique(['region_id', 'locale']);
                $table->index('locale');
            });
        }

        // 3. Currency Translations (Referencing existing currencies table)
        if (! Schema::hasTable('currency_translations')) {
            Schema::create('currency_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('currency_id')->constrained('currencies')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 100);
                $table->string('subunit_name', 50)->nullable();
                $table->timestamps();

                $table->unique(['currency_id', 'locale']);
                $table->index('locale');
            });
        }

        // 4. VAT Codes & VAT Code Translations
        if (! Schema::hasTable('vat_codes')) {
            Schema::create('vat_codes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 30)->unique();
                $table->string('country_iso', 2)->index();
                $table->decimal('rate', 5, 2);
                $table->string('nature', 10)->nullable(); // SDI Natura (N1, N2.1, etc.) or CH Ziffer
                $table->boolean('is_split_payment')->default(false);
                $table->boolean('is_reverse_charge')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vat_code_translations')) {
            Schema::create('vat_code_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('vat_code_id')->constrained('vat_codes')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->text('legal_description')->nullable();
                $table->timestamps();

                $table->unique(['vat_code_id', 'locale']);
                $table->index('locale');
            });
        }

        // 5. Payment Methods & Payment Method Translations
        if (! Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->boolean('is_electronic')->default(true);
                $table->boolean('requires_iban')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('payment_method_translations')) {
            Schema::create('payment_method_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('payment_method_id')->constrained('payment_methods')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['payment_method_id', 'locale']);
                $table->index('locale');
            });
        }

        // 6. Payment Term Translations (Referencing existing payment_terms table)
        if (! Schema::hasTable('payment_term_translations')) {
            Schema::create('payment_term_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('payment_term_id')->constrained('payment_terms')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->text('invoice_clause')->nullable();
                $table->timestamps();

                $table->unique(['payment_term_id', 'locale']);
                $table->index('locale');
            });
        }

        // 7. Document Statuses & Document Status Translations
        if (! Schema::hasTable('document_statuses')) {
            Schema::create('document_statuses', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('scope', 50)->index(); // quote, sales_order, invoice, purchase_order
                $table->string('code', 50);
                $table->string('color_badge', 10)->default('#64748B');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_terminal')->default(false);
                $table->timestamps();

                $table->unique(['scope', 'code']);
            });
        }

        if (! Schema::hasTable('document_status_translations')) {
            Schema::create('document_status_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('document_status_id')->constrained('document_statuses')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('label', 100);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['document_status_id', 'locale'], 'doc_stat_trans_unique');
                $table->index('locale');
            });
        }

        // 8. Inventory Movement Types & Translations
        if (! Schema::hasTable('inventory_movement_types')) {
            Schema::create('inventory_movement_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('direction', 10); // in, out, transfer
                $table->boolean('affects_cogs')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('inventory_movement_type_translations')) {
            Schema::create('inventory_movement_type_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('movement_type_id')->constrained('inventory_movement_types')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['movement_type_id', 'locale'], 'inv_mov_trans_unique');
                $table->index('locale');
            });
        }

        // 9. Expense Categories & Translations
        if (! Schema::hasTable('expense_categories')) {
            Schema::create('expense_categories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->boolean('requires_receipt')->default(true);
                $table->string('suggested_account_code', 50)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('expense_category_translations')) {
            Schema::create('expense_category_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('expense_category_id')->constrained('expense_categories')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['expense_category_id', 'locale'], 'exp_cat_trans_unique');
                $table->index('locale');
            });
        }

        // 10. Contact Categories & Translations
        if (! Schema::hasTable('contact_categories')) {
            Schema::create('contact_categories', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('contact_category_translations')) {
            Schema::create('contact_category_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('contact_category_id')->constrained('contact_categories')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->timestamps();

                $table->unique(['contact_category_id', 'locale'], 'cont_cat_trans_unique');
                $table->index('locale');
            });
        }

        // 11. Contact Types & Translations
        if (! Schema::hasTable('contact_types')) {
            Schema::create('contact_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('contact_type_translations')) {
            Schema::create('contact_type_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('contact_type_id')->constrained('contact_types')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->timestamps();

                $table->unique(['contact_type_id', 'locale'], 'cont_type_trans_unique');
                $table->index('locale');
            });
        }

        // 12. Project Statuses & Translations
        if (! Schema::hasTable('project_statuses')) {
            Schema::create('project_statuses', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('color_badge', 10)->default('#6366F1');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_terminal')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('project_status_translations')) {
            Schema::create('project_status_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('project_status_id')->constrained('project_statuses')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('label', 100);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['project_status_id', 'locale'], 'proj_stat_trans_unique');
                $table->index('locale');
            });
        }

        // 13. Task Statuses & Translations
        if (! Schema::hasTable('task_statuses')) {
            Schema::create('task_statuses', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('color_badge', 10)->default('#64748B');
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->boolean('is_terminal')->default(false);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('task_status_translations')) {
            Schema::create('task_status_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('task_status_id')->constrained('task_statuses')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('label', 100);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['task_status_id', 'locale'], 'task_stat_trans_unique');
                $table->index('locale');
            });
        }

        // 14. Workflow States & Translations
        if (! Schema::hasTable('workflow_states')) {
            Schema::create('workflow_states', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('scope', 50)->index(); // invoice_approval, po_approval, quote_approval
                $table->string('code', 50);
                $table->boolean('is_initial')->default(false);
                $table->boolean('is_final')->default(false);
                $table->timestamps();

                $table->unique(['scope', 'code']);
            });
        }

        if (! Schema::hasTable('workflow_state_translations')) {
            Schema::create('workflow_state_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('workflow_state_id')->constrained('workflow_states')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['workflow_state_id', 'locale'], 'wf_state_trans_unique');
                $table->index('locale');
            });
        }

        // 15. Approval Statuses & Translations
        if (! Schema::hasTable('approval_statuses')) {
            Schema::create('approval_statuses', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('color_badge', 10)->default('#F59E0B');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('approval_status_translations')) {
            Schema::create('approval_status_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('approval_status_id')->constrained('approval_statuses')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 100);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['approval_status_id', 'locale'], 'appr_stat_trans_unique');
                $table->index('locale');
            });
        }

        // 16. Notification Types & Translations
        if (! Schema::hasTable('notification_types')) {
            Schema::create('notification_types', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('channel', 20)->default('in_app'); // in_app, email, sms
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('notification_type_translations')) {
            Schema::create('notification_type_translations', function (Blueprint $table): void {
                $table->id();
                $table->foreignUuid('notification_type_id')->constrained('notification_types')->cascadeOnDelete();
                $table->string('locale', 5);
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->timestamps();

                $table->unique(['notification_type_id', 'locale'], 'notif_type_trans_unique');
                $table->index('locale');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_type_translations');
        Schema::dropIfExists('notification_types');
        Schema::dropIfExists('approval_status_translations');
        Schema::dropIfExists('approval_statuses');
        Schema::dropIfExists('workflow_state_translations');
        Schema::dropIfExists('workflow_states');
        Schema::dropIfExists('task_status_translations');
        Schema::dropIfExists('task_statuses');
        Schema::dropIfExists('project_status_translations');
        Schema::dropIfExists('project_statuses');
        Schema::dropIfExists('contact_type_translations');
        Schema::dropIfExists('contact_types');
        Schema::dropIfExists('contact_category_translations');
        Schema::dropIfExists('contact_categories');
        Schema::dropIfExists('expense_category_translations');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('inventory_movement_type_translations');
        Schema::dropIfExists('inventory_movement_types');
        Schema::dropIfExists('document_status_translations');
        Schema::dropIfExists('document_statuses');
        Schema::dropIfExists('payment_term_translations');
        Schema::dropIfExists('payment_method_translations');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('vat_code_translations');
        Schema::dropIfExists('vat_codes');
        Schema::dropIfExists('currency_translations');
        Schema::dropIfExists('region_translations');
        Schema::dropIfExists('regions');
        Schema::dropIfExists('country_translations');
        Schema::dropIfExists('countries');
    }
};
