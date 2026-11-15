<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('credit_scorecard_factors')) {
            Schema::create('credit_scorecard_factors', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 40);
                $table->string('name', 120);
                $table->string('source', 30);
                $table->decimal('weight', 6, 2);
                $table->boolean('is_active')->default(true);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('credit_rating_bands')) {
            Schema::create('credit_rating_bands', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 10);
                $table->string('name', 120);
                $table->decimal('min_score', 5, 2);
                $table->decimal('max_score', 5, 2);
                $table->string('risk_level', 20);
                $table->decimal('limit_multiplier', 6, 2)->default(0);
                $table->decimal('limit_ceiling', 15, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'min_score']);
            });
        }

        if (! Schema::hasTable('credit_scores')) {
            Schema::create('credit_scores', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->decimal('score', 5, 2);
                $table->uuid('rating_band_id')->nullable();
                $table->string('rating', 10);
                $table->string('risk_level', 20);
                $table->json('breakdown');
                $table->date('scored_on');
                $table->uuid('scored_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id', 'scored_on']);
            });
        }

        if (! Schema::hasTable('credit_limit_requests')) {
            Schema::create('credit_limit_requests', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->decimal('current_limit', 15, 2)->default(0);
                $table->decimal('requested_limit', 15, 2);
                $table->decimal('proposed_limit', 15, 2)->nullable();
                $table->decimal('approved_limit', 15, 2)->nullable();
                $table->string('currency', 3)->default('EUR');
                $table->string('status', 20)->default('pending');
                $table->uuid('score_id')->nullable();
                $table->text('reason')->nullable();
                $table->uuid('requested_by')->nullable();
                $table->uuid('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_note')->nullable();
                $table->date('valid_until')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'customer_id']);
            });
        }

        if (! Schema::hasTable('credit_insurance_policies')) {
            Schema::create('credit_insurance_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('insurer', 160);
                $table->string('policy_number', 80);
                $table->date('starts_on');
                $table->date('ends_on');
                $table->string('currency', 3)->default('EUR');
                $table->decimal('coverage_percentage', 5, 2)->default(90);
                $table->decimal('max_total_cover', 15, 2)->nullable();
                $table->string('status', 20)->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('credit_insurance_covers')) {
            Schema::create('credit_insurance_covers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('policy_id')->constrained('credit_insurance_policies')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->decimal('insured_limit', 15, 2);
                $table->string('status', 20)->default('granted');
                $table->date('granted_on')->nullable();
                $table->date('expires_on')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id', 'status']);
            });
        }

        if (! Schema::hasTable('credit_dunning_strategies')) {
            Schema::create('credit_dunning_strategies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 120);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->text('description')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('credit_dunning_steps')) {
            Schema::create('credit_dunning_steps', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('strategy_id')->constrained('credit_dunning_strategies')->cascadeOnDelete();
                $table->unsignedSmallInteger('step_order');
                $table->string('name', 120);
                $table->unsignedSmallInteger('days_after_due');
                $table->string('channel', 30);
                $table->string('template_key', 120)->nullable();
                $table->decimal('fee_amount', 15, 2)->default(0);
                $table->boolean('escalates')->default(false);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['strategy_id', 'step_order']);
            });
        }

        if (! Schema::hasTable('credit_collection_cases')) {
            Schema::create('credit_collection_cases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('case_number', 30);
                $table->string('status', 20)->default('open');
                $table->string('priority', 10)->default('normal');
                $table->uuid('collector_id')->nullable()->index();
                $table->uuid('strategy_id')->nullable();
                $table->unsignedSmallInteger('current_step_order')->default(0);
                $table->decimal('outstanding_amount', 15, 2)->default(0);
                $table->string('currency', 3)->default('EUR');
                $table->date('opened_on');
                $table->date('next_action_on')->nullable();
                $table->date('closed_on')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'case_number']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'customer_id']);
            });
        }

        if (! Schema::hasTable('credit_case_invoices')) {
            Schema::create('credit_case_invoices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('case_id')->constrained('credit_collection_cases')->cascadeOnDelete();
                $table->uuid('invoice_id');
                $table->string('invoice_number', 50);
                $table->date('due_on')->nullable();
                $table->decimal('outstanding_amount', 15, 2)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'invoice_id']);
            });
        }

        if (! Schema::hasTable('credit_collection_actions')) {
            Schema::create('credit_collection_actions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('case_id')->constrained('credit_collection_cases')->cascadeOnDelete();
                $table->uuid('step_id')->nullable();
                $table->string('action_type', 30);
                $table->string('channel', 30)->nullable();
                $table->date('performed_on');
                $table->uuid('performed_by')->nullable();
                $table->decimal('fee_amount', 15, 2)->default(0);
                $table->text('outcome')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'case_id']);
            });
        }

        if (! Schema::hasTable('credit_promises_to_pay')) {
            Schema::create('credit_promises_to_pay', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('case_id')->constrained('credit_collection_cases')->cascadeOnDelete();
                $table->decimal('promised_amount', 15, 2);
                $table->date('promised_on');
                $table->string('status', 20)->default('pending');
                $table->decimal('paid_amount', 15, 2)->nullable();
                $table->date('resolved_on')->nullable();
                $table->uuid('recorded_by')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status', 'promised_on']);
            });
        }

        if (! Schema::hasTable('credit_disputes')) {
            Schema::create('credit_disputes', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->uuid('case_id')->nullable()->index();
                $table->uuid('invoice_id')->nullable();
                $table->decimal('disputed_amount', 15, 2);
                $table->string('reason_code', 30);
                $table->string('status', 20)->default('open');
                $table->date('opened_on');
                $table->date('resolved_on')->nullable();
                $table->decimal('credited_amount', 15, 2)->nullable();
                $table->text('description')->nullable();
                $table->text('resolution_note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        foreach (['credit_disputes', 'credit_promises_to_pay', 'credit_collection_actions', 'credit_case_invoices', 'credit_collection_cases', 'credit_dunning_steps', 'credit_dunning_strategies', 'credit_insurance_covers', 'credit_insurance_policies', 'credit_limit_requests', 'credit_scores', 'credit_rating_bands', 'credit_scorecard_factors'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
