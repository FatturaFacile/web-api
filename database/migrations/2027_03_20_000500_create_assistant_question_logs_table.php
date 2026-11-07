<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_question_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('user_id')->index();
            $table->string('role', 60)->nullable();
            $table->text('question');
            $table->string('resolver', 60);
            $table->string('query_key', 80)->nullable()->index();
            $table->jsonb('parameters')->default('{}');
            $table->string('outcome', 20)->index();
            $table->unsignedInteger('row_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_question_logs');
    }
};
