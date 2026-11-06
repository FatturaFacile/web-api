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
        Schema::table('time_recording_profiles', function (Blueprint $table): void {
            $table->date('agreement_valid_from')->nullable();
            $table->date('agreement_valid_to')->nullable();
            $table->string('agreement_basis', 20)->nullable(); // art. 73b: collective, individual
            $table->unsignedTinyInteger('autonomy_percent')->nullable(); // art. 73b: at least 25%
            $table->string('collective_agreement_reference', 120)->nullable(); // art. 73a: the CCL allowing the waiver
            $table->text('annual_gross_salary')->nullable(); // art. 73a, encrypted
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('time_recording_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'agreement_valid_from', 'agreement_valid_to', 'agreement_basis', 'autonomy_percent',
                'collective_agreement_reference', 'annual_gross_salary',
            ]);
        });
    }
};
