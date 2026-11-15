<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comments with mentions and shared tags that attach to any record of the collaboration registry.
     */
    public function up(): void
    {
        if (! Schema::hasTable('collab_comments')) {
            Schema::create('collab_comments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('commentable_type', 40);
                $table->uuid('commentable_id');
                $table->uuid('user_id');
                $table->text('body');
                $table->json('mentioned_user_ids')->nullable();
                $table->timestamp('edited_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'commentable_type', 'commentable_id'], 'collab_comments_record_index');
                $table->index(['tenant_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('collab_tags')) {
            Schema::create('collab_tags', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 60);
                $table->string('color', 7)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'name']);
            });
        }

        if (! Schema::hasTable('collab_taggables')) {
            Schema::create('collab_taggables', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('tag_id')->constrained('collab_tags')->cascadeOnDelete();
                $table->string('taggable_type', 40);
                $table->uuid('taggable_id');
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'collab_taggables_unique');
                $table->index(['tenant_id', 'taggable_type', 'taggable_id'], 'collab_taggables_record_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collab_taggables');
        Schema::dropIfExists('collab_tags');
        Schema::dropIfExists('collab_comments');
    }
};
