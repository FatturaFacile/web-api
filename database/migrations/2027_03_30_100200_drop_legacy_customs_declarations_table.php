<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `customs_declarations` comes from the first inventory migration and has neither a model nor any code: customs
     * declarations live in `customs_goods_declarations` (module 32_Customs), which is not touched here.
     *
     * The table is dropped only when it holds no row, read under an exclusive lock so nothing can be written between the
     * check and the drop. A populated table is kept, and a warning tells the operator how many rows wait for a review.
     */
    public function up(): void
    {
        if (! Schema::hasTable('customs_declarations')) {
            return;
        }

        DB::transaction(function (): void {
            DB::statement('LOCK TABLE customs_declarations IN ACCESS EXCLUSIVE MODE');

            $rows = DB::table('customs_declarations')->count();

            if ($rows > 0) {
                Log::warning('Legacy table customs_declarations kept: it still holds rows. Review them, move what is needed to customs_goods_declarations, then drop the table.', ['rows' => $rows]);

                return;
            }

            Schema::drop('customs_declarations');
        });
    }

    /**
     * Recreates the empty legacy table with its original structure, so that rolling back the first migrations still works.
     */
    public function down(): void
    {
        if (Schema::hasTable('customs_declarations')) {
            return;
        }

        Schema::create('customs_declarations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ddt_id')->nullable()->constrained('delivery_notes')->nullOnDelete();
            $table->string('declaration_number', 50)->index();
            $table->string('mrn_number', 50)->nullable()->index();
            $table->string('type', 30)->default('export_it_to_ch');
            $table->string('customs_office', 100)->nullable();
            $table->string('customs_agent', 100)->nullable();
            $table->decimal('declared_value', 15, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('incoterms', 10)->default('DAP');
            $table->string('status', 30)->default('draft');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'declaration_number']);
        });
    }
};
