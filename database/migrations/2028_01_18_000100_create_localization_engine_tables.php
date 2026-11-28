<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 002 — Localization and i18n engine (issue #331). Dialect-agnostic: SQLite and PostgreSQL.
 *
 *  - translations: statutory keys can be locked and retired keys flagged; lookup indexes by (locale, group).
 *  - translation tables of the master data the UI translates (products, categories, units, shipping reasons, standard
 *    texts; payment terms already have theirs) and the two master data tables that did not exist yet.
 *  - a frozen `locale` on the documents (invoices, quotes, delivery notes, reminders, purchase orders). Users already
 *    carry `locale` and contacts `document_language`.
 */
return new class extends Migration
{
    private const array DOCUMENT_TABLES = ['invoices', 'quotes', 'delivery_notes', 'payment_reminders', 'purchase_orders'];

    public function up(): void
    {
        Schema::table('translations', function (Blueprint $table): void {
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_deprecated')->default(false);
            $table->index(['locale', 'group'], 'translations_locale_group_index');
            $table->unique(['tenant_id', 'locale', 'group', 'key'], 'translations_tenant_locale_group_key_unique');
        });

        $this->masterData();
        $this->translationTables();

        foreach (self::DOCUMENT_TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('locale', 10)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::DOCUMENT_TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn('locale');
            });
        }

        foreach (['standard_text_translations', 'shipping_reason_translations', 'unit_of_measure_translations', 'product_category_translations', 'product_translations', 'standard_texts', 'shipping_reasons'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('translations', function (Blueprint $table): void {
            $table->dropUnique('translations_tenant_locale_group_key_unique');
            $table->dropIndex('translations_locale_group_index');
            $table->dropColumn(['is_locked', 'is_deprecated']);
        });
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function masterData(): void
    {
        Schema::create('shipping_reasons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code'], 'shipping_reasons_tenant_code_unique');
        });

        Schema::create('standard_texts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 60);
            $table->string('title');
            $table->text('body')->nullable();
            $table->boolean('is_active')->default(true);
            $this->audit($table);
            $table->unique(['tenant_id', 'code'], 'standard_texts_tenant_code_unique');
        });
    }

    private function translationTables(): void
    {
        $definitions = [
            'product_translations' => ['product_id', 'products', fn (Blueprint $t) => [$t->string('name')->nullable(), $t->text('description')->nullable()]],
            'product_category_translations' => ['product_category_id', 'product_categories', fn (Blueprint $t) => [$t->string('name')->nullable(), $t->text('description')->nullable()]],
            'unit_of_measure_translations' => ['unit_of_measure_id', 'units_of_measure', fn (Blueprint $t) => [$t->string('name')->nullable(), $t->string('symbol', 20)->nullable()]],
            'shipping_reason_translations' => ['shipping_reason_id', 'shipping_reasons', fn (Blueprint $t) => [$t->string('name')->nullable(), $t->text('description')->nullable()]],
            'standard_text_translations' => ['standard_text_id', 'standard_texts', fn (Blueprint $t) => [$t->string('title')->nullable(), $t->text('body')->nullable()]],
        ];

        foreach ($definitions as $name => [$foreignKey, $parent, $columns]) {
            Schema::create($name, function (Blueprint $table) use ($name, $foreignKey, $parent, $columns): void {
                $table->id();
                $table->foreignUuid($foreignKey)->constrained($parent)->cascadeOnDelete();
                $table->string('locale', 10);
                $columns($table);
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $this->audit($table);
                $table->unique([$foreignKey, 'locale'], $name.'_'.$foreignKey.'_locale_unique');
                $table->index('locale', $name.'_locale_index');
            });
        }
    }
};
