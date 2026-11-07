<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge base on top of the existing articles: categories, internal or public visibility, versions, full-text search
 * and links between articles and tickets. Existing articles stay published and become internal, so nothing is exposed
 * on the portal until someone chooses to. Additive and defaulted, safe on populated tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esm_knowledge_categories', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->string('name', 150);
            $table->string('visibility', 20)->default('internal');
            $table->unsignedInteger('position')->default(100);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'visibility']);
        });

        // The self reference needs the primary key, which exists only once the table is created.
        Schema::table('esm_knowledge_categories', function (Blueprint $table): void {
            $table->foreign('parent_id')->references('id')->on('esm_knowledge_categories')->nullOnDelete();
        });

        Schema::table('esm_knowledge_articles', function (Blueprint $table): void {
            $table->foreignUuid('knowledge_category_id')->nullable()->constrained('esm_knowledge_categories')->nullOnDelete();
            $table->string('visibility', 20)->default('internal');
            $table->unsignedInteger('current_version')->default(1);
            $table->uuid('author_user_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->index(['tenant_id', 'is_published', 'visibility']);
        });

        DB::statement("CREATE INDEX esm_knowledge_articles_search_idx ON esm_knowledge_articles USING GIN (to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(summary, '') || ' ' || coalesce(content, '')))");

        Schema::create('esm_knowledge_article_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('article_id')->constrained('esm_knowledge_articles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 200);
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->uuid('author_user_id')->nullable();
            $table->string('change_note', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'article_id']);
            $table->unique(['article_id', 'version']);
        });

        Schema::create('esm_ticket_knowledge_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ticket_id')->constrained('esm_tickets')->cascadeOnDelete();
            $table->foreignUuid('article_id')->constrained('esm_knowledge_articles')->cascadeOnDelete();
            $table->uuid('linked_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'article_id']);
            $table->unique(['ticket_id', 'article_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esm_ticket_knowledge_links');
        Schema::dropIfExists('esm_knowledge_article_versions');
        DB::statement('DROP INDEX IF EXISTS esm_knowledge_articles_search_idx');

        Schema::table('esm_knowledge_articles', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'is_published', 'visibility']);
            $table->dropConstrainedForeignId('knowledge_category_id');
            $table->dropColumn(['visibility', 'current_version', 'author_user_id', 'published_at']);
        });

        Schema::dropIfExists('esm_knowledge_categories');
    }
};
