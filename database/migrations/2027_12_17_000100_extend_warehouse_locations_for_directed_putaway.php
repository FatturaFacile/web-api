<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 051: shelf level, 3D coordinates, physical dimensions and mixing rules on bins; temperature control on zones;
 * ABC / temperature / packaging filters on putaway rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouse_zones', function (Blueprint $table): void {
            $table->string('temperature_control', 20)->default('ambient');
            $table->string('security_level', 30)->nullable();
        });

        Schema::table('warehouse_bins', function (Blueprint $table): void {
            $table->string('shelf_code', 50)->nullable();
            $table->decimal('coord_x', 10, 2)->nullable();
            $table->decimal('coord_y', 10, 2)->nullable();
            $table->decimal('coord_z', 10, 2)->nullable();
            $table->decimal('length_cm', 8, 2)->nullable();
            $table->decimal('width_cm', 8, 2)->nullable();
            $table->decimal('height_cm', 8, 2)->nullable();
            $table->boolean('allow_mixed_skus')->default(true);
            $table->boolean('allow_mixed_lots')->default(true);
            $table->string('block_reason', 255)->nullable();
            $table->string('full_path_code', 255)->nullable()->index();
        });

        Schema::table('putaway_rules', function (Blueprint $table): void {
            $table->string('abc_class', 1)->nullable();
            $table->string('temperature_control', 20)->nullable();
            $table->string('packaging_unit', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('putaway_rules', function (Blueprint $table): void {
            $table->dropColumn(['abc_class', 'temperature_control', 'packaging_unit']);
        });

        Schema::table('warehouse_bins', function (Blueprint $table): void {
            $table->dropIndex(['full_path_code']);
            $table->dropColumn(['shelf_code', 'coord_x', 'coord_y', 'coord_z', 'length_cm', 'width_cm', 'height_cm', 'allow_mixed_skus', 'allow_mixed_lots', 'block_reason', 'full_path_code']);
        });

        Schema::table('warehouse_zones', function (Blueprint $table): void {
            $table->dropColumn(['temperature_control', 'security_level']);
        });
    }
};
