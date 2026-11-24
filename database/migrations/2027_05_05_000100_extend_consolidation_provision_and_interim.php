<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Additive columns of the fourth round of the consolidation: the treatment of a negative difference at the first
     * consolidation (group option), the consolidation provision for future risks and charges of an acquisition
     * (expected charges and the years over which the provision is used), and the basis on which a member is read (its
     * own fiscal year or an interim statement of twelve months ending at the consolidation date). Every default keeps the
     * behaviour of the earlier versions.
     */
    public function up(): void
    {
        Schema::table('gl_consolidation_groups', function (Blueprint $table): void {
            $table->string('negative_goodwill_treatment', 30)->default('consolidation_reserve');
        });

        Schema::table('gl_consolidation_members', function (Blueprint $table): void {
            $table->string('consolidation_basis', 30)->default('own_year');
        });

        Schema::table('gl_consolidation_acquisitions', function (Blueprint $table): void {
            $table->decimal('expected_future_charges', 19, 4)->nullable();
            $table->unsignedSmallInteger('provision_release_years')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gl_consolidation_acquisitions', function (Blueprint $table): void {
            $table->dropColumn(['expected_future_charges', 'provision_release_years']);
        });
        Schema::table('gl_consolidation_members', function (Blueprint $table): void {
            $table->dropColumn('consolidation_basis');
        });
        Schema::table('gl_consolidation_groups', function (Blueprint $table): void {
            $table->dropColumn('negative_goodwill_treatment');
        });
    }
};
