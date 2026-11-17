<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer registry 360: company hierarchy, role-based contact persons, unlimited typed addresses with per-type
 * defaults and certified fiscal data (consents live in the CRM consent register).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->uuid('parent_id')->nullable()->after('tenant_id')->index();
            $table->string('hierarchy_type', 20)->nullable()->after('parent_id'); // holding, subsidiary, branch
            $table->timestamp('fiscal_verified_at')->nullable();
            $table->string('fiscal_verification_source', 30)->nullable(); // vies, zefix, checksum
        });

        // `type` was an enum (billing, shipping): widen it to a plain string so legal and site addresses fit.
        Schema::table('contact_addresses', function (Blueprint $table): void {
            $table->string('type_tmp', 20)->nullable();
        });
        DB::table('contact_addresses')->update(['type_tmp' => DB::raw('type')]);

        Schema::table('contact_addresses', function (Blueprint $table): void {
            $table->dropIndex(['contact_id', 'type']);
        });
        Schema::table('contact_addresses', function (Blueprint $table): void {
            $table->dropColumn('type');
        });
        Schema::table('contact_addresses', function (Blueprint $table): void {
            $table->string('type', 20)->default('billing');
        });
        DB::table('contact_addresses')->update(['type' => DB::raw('type_tmp')]);
        Schema::table('contact_addresses', function (Blueprint $table): void {
            $table->dropColumn('type_tmp');
            $table->string('label', 100)->nullable();
            $table->string('recipient_name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->index(['contact_id', 'type']);
        });

        Schema::create('contact_persons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('first_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('job_title', 100)->nullable();
            $table->string('role', 30)->default('other'); // administration, purchasing, logistics, technical, management, sales, other
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('language', 5)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'contact_id']);
            $table->index(['contact_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_persons');

        Schema::table('contact_addresses', function (Blueprint $table): void {
            $table->dropIndex(['contact_id', 'type']);
            $table->dropColumn(['label', 'recipient_name', 'is_default', 'notes']);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['parent_id', 'hierarchy_type', 'fiscal_verified_at', 'fiscal_verification_source']);
        });

        Schema::table('contact_addresses', function (Blueprint $table): void {
            $table->index(['contact_id', 'type']);
        });
    }
};
