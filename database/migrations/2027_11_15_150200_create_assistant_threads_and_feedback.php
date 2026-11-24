<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A thread groups the questions one user asked in one conversation; the answer rows are never stored, only what was
     * asked, how it resolved and the optional feedback of the user on the answer.
     */
    public function up(): void
    {
        Schema::create('assistant_threads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('user_id')->index();
            $table->string('title', 120);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('assistant_question_logs', function (Blueprint $table): void {
            $table->uuid('thread_id')->nullable()->index();
            $table->string('feedback', 20)->nullable();
            $table->text('feedback_comment')->nullable();
            $table->timestamp('feedback_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_question_logs', function (Blueprint $table): void {
            $table->dropColumn(['thread_id', 'feedback', 'feedback_comment', 'feedback_at']);
        });

        Schema::dropIfExists('assistant_threads');
    }
};
