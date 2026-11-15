<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Practice management extensions: matter expenses, the append-only trust (client funds) ledger, conflict-of-interest
     * parties and checks, and fee proposals that convert into a matter. Business rules (no negative trust balance,
     * immutable ledger rows) are enforced by the application, never by the engine.
     */
    public function up(): void
    {
        Schema::create('practice_expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('expense_date');
            $table->string('category', 30)->default('other');
            $table->string('description', 255);
            $table->decimal('amount', 15, 2);
            $table->boolean('is_billable')->default(true);
            $table->boolean('is_billed')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'legal_case_id']);
            $table->index(['tenant_id', 'expense_date']);
        });

        Schema::create('practice_trust_transactions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('client_id')->constrained('contacts')->restrictOnDelete();
            $table->foreignUuid('legal_case_id')->nullable()->constrained('legal_cases')->restrictOnDelete();
            $table->string('type', 20);
            $table->date('occurred_on');
            $table->decimal('amount', 15, 2);
            $table->decimal('signed_amount', 15, 2);
            $table->decimal('balance_after', 15, 2);
            $table->string('reference', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->uuid('reverses_id')->nullable();
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id', 'legal_case_id']);
            $table->unique('reverses_id');
        });

        Schema::create('practice_case_parties', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('legal_case_id')->constrained('legal_cases')->cascadeOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('name', 190);
            $table->string('role', 20);
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'name']);
        });

        Schema::create('practice_conflict_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('subject_name', 190);
            $table->string('subject_role', 20);
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('result', 10);
            $table->json('matches');
            $table->string('review_notes', 500)->nullable();
            $table->foreignUuid('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'result']);
        });

        Schema::create('practice_fee_proposals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('number', 30);
            $table->foreignUuid('client_id')->constrained('contacts')->restrictOnDelete();
            $table->string('title', 190);
            $table->text('scope')->nullable();
            $table->string('fee_type', 10);
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->decimal('fixed_fee', 15, 2)->nullable();
            $table->decimal('estimated_total', 15, 2);
            $table->date('valid_until')->nullable();
            $table->string('status', 10)->default('draft');
            $table->foreignUuid('conflict_check_id')->nullable()->constrained('practice_conflict_checks')->nullOnDelete();
            $table->foreignUuid('legal_case_id')->nullable()->constrained('legal_cases')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_fee_proposals');
        Schema::dropIfExists('practice_conflict_checks');
        Schema::dropIfExists('practice_case_parties');
        Schema::dropIfExists('practice_trust_transactions');
        Schema::dropIfExists('practice_expenses');
    }
};
