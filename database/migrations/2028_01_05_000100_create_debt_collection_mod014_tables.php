<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dunning and debt collection (Mod 014, Mahnwesen): policies with their dunning levels, statutory interest rate
 * periods, text templates, exclusions, payment plans, one case per open item, notices with their lines and PEC
 * receipts, dispatch batches, the Swiss debt enforcement offices and dossiers, and the customer timeline.
 *
 * Global rows (tenant_id null) are the seeded statutory rates and texts; a tenant row overrides them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('dc_policies')) {
            Schema::create('dc_policies', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('jurisdiction', 2); // CH, IT
                $table->string('currency', 3)->nullable(); // null = any currency
                $table->uuid('customer_id')->nullable(); // customer-specific override of the tenant policy
                $table->boolean('is_default')->default(false);
                $table->boolean('is_active')->default(true);
                $table->decimal('min_overdue_amount', 15, 2)->default(0);
                $table->string('interest_basis', 20)->default('actual_365'); // actual_365, actual_360, actual_actual
                $table->decimal('contractual_rate_percent', 7, 4)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'jurisdiction', 'is_active']);
            });
        }

        if (! Schema::hasTable('dc_policy_levels')) {
            Schema::create('dc_policy_levels', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('policy_id')->constrained('dc_policies')->cascadeOnDelete();
                $table->unsignedTinyInteger('level'); // 0 courtesy, 1 first, 2 second, 3 final notice
                $table->integer('min_days_overdue')->default(0); // negative = before the due date
                $table->unsignedSmallInteger('wait_days')->default(0); // after the previous notice
                $table->unsignedSmallInteger('deadline_days')->default(10); // new deadline granted by the notice
                $table->decimal('fee_amount', 15, 2)->default(0);
                $table->boolean('charge_interest')->default(false);
                $table->json('channels')->nullable();
                $table->boolean('legal_warning')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['policy_id', 'level']);
                $table->index(['tenant_id', 'policy_id']);
            });
        }

        if (! Schema::hasTable('dc_interest_rates')) {
            Schema::create('dc_interest_rates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('jurisdiction', 2);
                $table->string('kind', 20); // statutory_ch, ecb_it, contractual
                $table->decimal('base_rate_percent', 7, 4);
                $table->decimal('markup_percent', 7, 4)->default(0);
                $table->date('effective_from');
                $table->date('effective_to')->nullable();
                $table->string('notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'jurisdiction', 'effective_from']);
            });
        }

        if (! Schema::hasTable('dc_templates')) {
            Schema::create('dc_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('code', 60); // level_0..level_3, messa_in_mora, betreibung, labels
                $table->string('locale', 2);
                $table->string('jurisdiction', 2)->nullable();
                $table->string('subject', 255)->default('');
                $table->longText('body');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'code', 'locale']);
            });
        }

        if (! Schema::hasTable('dc_exclusions')) {
            Schema::create('dc_exclusions', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->uuid('receivable_item_id')->nullable(); // null = the whole customer
                $table->string('type', 20); // manual, dispute, legal_hold
                $table->string('reason', 500);
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamp('released_at')->nullable();
                $table->uuid('released_by')->nullable();
                $table->text('release_note')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id', 'released_at']);
            });
        }

        if (! Schema::hasTable('dc_payment_plans')) {
            Schema::create('dc_payment_plans', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('currency', 3);
                $table->decimal('total_amount', 15, 2);
                $table->date('start_date');
                $table->date('end_date');
                $table->string('status', 20)->default('active'); // active, completed, defaulted, cancelled
                $table->json('receivable_item_ids')->nullable(); // empty = every open item of the customer
                $table->json('installments')->nullable();
                $table->text('notes')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id', 'status']);
            });
        }

        if (! Schema::hasTable('dc_batches')) {
            Schema::create('dc_batches', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('channel', 20);
                $table->string('status', 20)->default('pending'); // pending, processing, completed, partial, failed
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('sent')->default(0);
                $table->unsignedInteger('failed')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('dc_cases')) {
            Schema::create('dc_cases', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->uuid('receivable_item_id');
                $table->string('jurisdiction', 2);
                $table->string('currency', 3);
                $table->unsignedTinyInteger('current_level')->nullable();
                $table->date('last_notice_on')->nullable();
                $table->date('deadline_on')->nullable();
                $table->string('status', 20)->default('open'); // open, enforcement, closed
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'receivable_item_id']);
                $table->index(['tenant_id', 'customer_id']);
            });
        }

        if (! Schema::hasTable('dc_notices')) {
            Schema::create('dc_notices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('number', 40);
                $table->string('kind', 20)->default('reminder'); // reminder, messa_in_mora
                $table->unsignedTinyInteger('level');
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('jurisdiction', 2);
                $table->string('currency', 3);
                $table->decimal('principal', 15, 2);
                $table->decimal('interest', 15, 2)->default(0);
                $table->decimal('fee', 15, 2)->default(0);
                $table->decimal('total', 15, 2);
                $table->date('issued_on');
                $table->date('new_due_date')->nullable();
                $table->string('channel', 20);
                $table->string('status', 20)->default('draft'); // draft, queued, sent, delivered, failed, cancelled
                $table->string('locale', 2)->default('it');
                $table->string('subject', 255)->default('');
                $table->longText('body')->nullable();
                $table->foreignUuid('batch_id')->nullable()->constrained('dc_batches')->nullOnDelete();
                $table->string('pec_message_id', 255)->nullable()->index();
                $table->timestamp('sent_at')->nullable();
                $table->text('error')->nullable();
                $table->unsignedSmallInteger('attempts')->default(0);
                $table->uuid('charge_receivable_item_id')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'number']);
                $table->index(['tenant_id', 'customer_id', 'status']);
            });
        }

        if (! Schema::hasTable('dc_notice_lines')) {
            Schema::create('dc_notice_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('notice_id')->constrained('dc_notices')->cascadeOnDelete();
                $table->uuid('receivable_item_id');
                $table->string('document_number', 100)->nullable();
                $table->date('due_date');
                $table->decimal('principal', 15, 2);
                $table->decimal('interest', 15, 2)->default(0);
                $table->unsignedInteger('interest_days')->default(0);
                $table->decimal('rate_percent', 9, 4)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'notice_id']);
                $table->index(['tenant_id', 'receivable_item_id']);
            });
        }

        if (! Schema::hasTable('dc_pec_receipts')) {
            Schema::create('dc_pec_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('notice_id')->constrained('dc_notices')->cascadeOnDelete();
                $table->string('kind', 20); // accettazione, consegna, mancata_consegna, non_accettazione
                $table->string('message_id', 255)->nullable();
                $table->timestamp('received_at');
                $table->text('notes')->nullable();
                $table->string('attachment_path', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'notice_id']);
            });
        }

        if (! Schema::hasTable('dc_enforcement_offices')) {
            Schema::create('dc_enforcement_offices', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 200);
                $table->string('postal_code_from', 10)->nullable();
                $table->string('postal_code_to', 10)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('postal_code', 10)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('canton', 2)->nullable();
                $table->string('email', 255)->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'postal_code_from']);
            });
        }

        if (! Schema::hasTable('dc_dossiers')) {
            Schema::create('dc_dossiers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('number', 40);
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('currency', 3);
                $table->decimal('principal', 15, 2);
                $table->decimal('interest', 15, 2)->default(0);
                $table->decimal('fee', 15, 2)->default(0);
                $table->decimal('total', 15, 2);
                $table->string('status', 20)->default('generated'); // generated, submitted, closed
                $table->uuid('office_id')->nullable();
                $table->json('office_snapshot')->nullable();
                $table->json('receivable_item_ids');
                $table->json('snapshot')->nullable();
                $table->string('locale', 2)->default('de');
                $table->string('pdf_path', 500)->nullable();
                $table->string('bundle_path', 500)->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->uuid('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['tenant_id', 'number']);
                $table->index(['tenant_id', 'customer_id']);
            });
        }

        if (! Schema::hasTable('dc_events')) {
            Schema::create('dc_events', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('event', 40);
                $table->unsignedTinyInteger('level')->nullable();
                $table->decimal('amount', 15, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->string('subject_type', 30);
                $table->string('subject_id', 36);
                $table->json('payload')->nullable();
                $table->uuid('actor_id')->nullable();
                $table->timestamp('occurred_at');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'customer_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (['dc_events', 'dc_dossiers', 'dc_enforcement_offices', 'dc_pec_receipts', 'dc_notice_lines', 'dc_notices', 'dc_cases', 'dc_batches', 'dc_payment_plans', 'dc_exclusions', 'dc_templates', 'dc_interest_rates', 'dc_policy_levels', 'dc_policies'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
