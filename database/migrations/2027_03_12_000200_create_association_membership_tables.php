<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fee plans, membership types, the memberships of each year and the credentials (QR and badge) of their holders.
     * A QR credential stores only a random public id: the printed code is that id plus a signature.
     */
    public function up(): void
    {
        Schema::create('assoc_fee_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->string('frequency', 20);
            $table->decimal('amount', 12, 2);
            $table->unsignedSmallInteger('lessons_included')->nullable();
            $table->unsignedSmallInteger('instalments')->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->uuid('revenue_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('assoc_membership_types', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 50);
            $table->string('name');
            $table->foreignUuid('registry_type_id')->nullable()->constrained('assoc_registry_types')->nullOnDelete();
            $table->foreignUuid('fee_plan_id')->nullable()->constrained('assoc_fee_plans')->nullOnDelete();
            $table->boolean('requires_board_decision')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('assoc_memberships', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->restrictOnDelete();
            $table->foreignUuid('year_id')->constrained('assoc_years')->restrictOnDelete();
            $table->foreignUuid('membership_type_id')->constrained('assoc_membership_types')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('card_number', 40)->nullable();
            $table->unsignedBigInteger('card_sequence')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('valid_from');
            $table->date('valid_to');
            $table->uuid('renewed_from_id')->nullable();
            $table->date('ceased_on')->nullable();
            $table->string('status_reason', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'valid_to']);
            $table->index(['tenant_id', 'year_id']);
            $table->index('member_id');
        });

        Schema::create('assoc_credentials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('assoc_members')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->string('public_id', 64)->nullable()->unique();
            $table->string('lookup_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'member_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX assoc_fee_plans_code_unique ON assoc_fee_plans (tenant_id, code) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_membership_types_code_unique ON assoc_membership_types (tenant_id, code) WHERE deleted_at IS NULL');
            DB::statement('CREATE UNIQUE INDEX assoc_memberships_card_unique ON assoc_memberships (tenant_id, year_id, card_number) WHERE card_number IS NOT NULL AND deleted_at IS NULL');
            DB::statement("CREATE UNIQUE INDEX assoc_memberships_year_unique ON assoc_memberships (member_id, year_id, membership_type_id) WHERE deleted_at IS NULL AND status NOT IN ('rejected', 'ceased')");
            DB::statement('ALTER TABLE assoc_fee_plans ADD CONSTRAINT assoc_fee_plans_amount_check CHECK (amount >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assoc_credentials');
        Schema::dropIfExists('assoc_memberships');
        Schema::dropIfExists('assoc_membership_types');
        Schema::dropIfExists('assoc_fee_plans');
    }
};
