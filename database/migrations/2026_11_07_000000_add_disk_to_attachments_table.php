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
        if (Schema::hasTable('attachments') && ! Schema::hasColumn('attachments', 'disk')) {
            Schema::table('attachments', function (Blueprint $table): void {
                $table->string('disk', 50)->nullable()->after('original_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('attachments') && Schema::hasColumn('attachments', 'disk')) {
            Schema::table('attachments', function (Blueprint $table): void {
                $table->dropColumn('disk');
            });
        }
    }
};
