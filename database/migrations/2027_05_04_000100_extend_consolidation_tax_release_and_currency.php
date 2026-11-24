<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive columns of the third round of the consolidation: deferred tax on the elimination of unrealised
     * intragroup profit (group toggle and rate, tax rate of each member), the rule of the first year of the release of
     * fair-value adjustments and the later translation of goodwill (group options), the useful life of each
     * fair-value adjustment, and the currency in which the amounts of an acquisition are entered. Every default keeps
     * the behaviour of the earlier versions.
     */
    public function up(): void
    {
        Schema::table('gl_consolidation_groups', function (Blueprint $table): void {
            $table->boolean('deferred_tax_on_unrealised_profit')->default(false);
            $table->decimal('unrealised_profit_tax_rate', 7, 4)->nullable();
            $table->string('fair_value_first_year_rule', 20)->default('pro_rata');
            $table->string('goodwill_translation', 20)->default('historical');
        });

        Schema::table('gl_consolidation_members', function (Blueprint $table): void {
            $table->decimal('tax_rate', 7, 4)->nullable();
        });

        Schema::table('gl_consolidation_fair_value_adjustments', function (Blueprint $table): void {
            $table->unsignedSmallInteger('useful_life_years')->nullable();
        });

        Schema::table('gl_consolidation_acquisitions', function (Blueprint $table): void {
            $table->string('input_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gl_consolidation_acquisitions', function (Blueprint $table): void {
            $table->dropColumn('input_currency');
        });
        Schema::table('gl_consolidation_fair_value_adjustments', function (Blueprint $table): void {
            $table->dropColumn('useful_life_years');
        });
        Schema::table('gl_consolidation_members', function (Blueprint $table): void {
            $table->dropColumn('tax_rate');
        });
        Schema::table('gl_consolidation_groups', function (Blueprint $table): void {
            $table->dropColumn(['deferred_tax_on_unrealised_profit', 'unrealised_profit_tax_rate', 'fair_value_first_year_rule', 'goodwill_translation']);
        });
    }
};
