<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Message templates per language, and a delivery log that also covers messages that are not about a document.
     * The log keeps the subject and the template but never the body of a message: bodies carry personal data.
     */
    public function up(): void
    {
        if (! Schema::hasTable('communication_templates')) {
            Schema::create('communication_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 100);
                $table->string('channel', 20);
                $table->string('locale', 10);
                $table->string('subject', 255)->nullable();
                $table->text('body');
                $table->boolean('is_active')->default(true);
                $table->uuid('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'code', 'channel', 'locale'], 'uq_communication_template');
            });
        }

        Schema::table('delivery_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('delivery_logs', 'message_type')) {
                $table->string('message_type', 20)->default('document');
            }

            if (! Schema::hasColumn('delivery_logs', 'subject')) {
                $table->string('subject', 255)->nullable();
            }

            if (! Schema::hasColumn('delivery_logs', 'template_code')) {
                $table->string('template_code', 100)->nullable();
            }

            if (! Schema::hasColumn('delivery_logs', 'locale')) {
                $table->string('locale', 10)->nullable();
            }

            $table->string('document_type', 30)->nullable()->change();
            $table->uuid('document_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('delivery_logs', function (Blueprint $table): void {
            $table->dropColumn(['message_type', 'subject', 'template_code', 'locale']);
        });

        Schema::dropIfExists('communication_templates');
    }
};
