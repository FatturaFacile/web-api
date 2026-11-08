<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise CRM engagement: configurable lead scoring, activity reminders, marketing segments, GDPR consent records,
 * campaign delivery tracking and the audited contact merge history. Every change is additive and guarded, so the
 * migration can be re-run on a partially migrated database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crm_lead_scoring_rules')) {
            Schema::create('crm_lead_scoring_rules', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 150);
                $table->string('rule_type', 20); // field, activity
                $table->string('field', 50)->nullable();
                $table->string('operator', 30)->nullable();
                $table->string('value', 255)->nullable();
                $table->string('activity_type', 30)->nullable();
                $table->string('activity_status', 30)->nullable();
                $table->integer('points');
                $table->integer('max_points')->nullable();
                $table->unsignedInteger('sequence')->default(10);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'is_active', 'sequence']);
            });
        }

        Schema::table('crm_leads', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_leads', 'score_breakdown')) {
                $table->jsonb('score_breakdown')->nullable();
            }
            if (! Schema::hasColumn('crm_leads', 'score_calculated_at')) {
                $table->timestamp('score_calculated_at')->nullable();
            }
        });

        Schema::table('crm_activities', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_activities', 'reminder_at')) {
                $table->timestamp('reminder_at')->nullable();
            }
            if (! Schema::hasColumn('crm_activities', 'reminder_sent_at')) {
                $table->timestamp('reminder_sent_at')->nullable();
            }
        });

        if (! collect(Schema::getIndexes('crm_activities'))->contains(fn (array $index): bool => $index['columns'] === ['tenant_id', 'reminder_at'])) {
            Schema::table('crm_activities', function (Blueprint $table): void {
                $table->index(['tenant_id', 'reminder_at']);
            });
        }

        Schema::table('crm_campaign_members', function (Blueprint $table): void {
            if (! Schema::hasColumn('crm_campaign_members', 'sent_at')) {
                $table->timestamp('sent_at')->nullable();
            }
            if (! Schema::hasColumn('crm_campaign_members', 'suppression_reason')) {
                $table->string('suppression_reason', 50)->nullable();
            }
        });

        if (! Schema::hasTable('crm_segments')) {
            Schema::create('crm_segments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name', 150);
                $table->text('description')->nullable();
                $table->string('entity', 20)->default('contacts'); // contacts, leads
                $table->jsonb('filters');
                $table->boolean('is_active')->default(true);
                $table->uuid('created_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'entity']);
            });
        }

        if (! Schema::hasTable('crm_consents')) {
            Schema::create('crm_consents', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->cascadeOnDelete();
                $table->foreignUuid('lead_id')->nullable()->constrained('crm_leads')->cascadeOnDelete();
                $table->string('channel', 20); // email, sms, phone, post
                $table->string('purpose', 30); // marketing, newsletter, profiling
                $table->string('status', 20); // granted, withdrawn
                $table->string('source', 40); // web_form, import, phone, in_person, api, unsubscribe_link, lead_conversion
                $table->timestamp('captured_at');
                $table->string('ip_address', 45)->nullable();
                $table->text('evidence')->nullable();
                $table->uuid('recorded_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'contact_id', 'channel', 'purpose']);
                $table->index(['tenant_id', 'lead_id', 'channel', 'purpose']);
            });
        }

        if (! Schema::hasTable('crm_contact_merges')) {
            Schema::create('crm_contact_merges', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->uuid('survivor_contact_id')->index();
                $table->uuid('merged_contact_id')->index();
                $table->jsonb('repointed');
                $table->jsonb('retained')->nullable();
                $table->jsonb('filled_fields')->nullable();
                $table->jsonb('merged_snapshot');
                $table->uuid('merged_by_user_id')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_contact_merges');
        Schema::dropIfExists('crm_consents');
        Schema::dropIfExists('crm_segments');

        Schema::table('crm_campaign_members', function (Blueprint $table): void {
            $table->dropColumn(['sent_at', 'suppression_reason']);
        });

        Schema::table('crm_activities', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'reminder_at']);
            $table->dropColumn(['reminder_at', 'reminder_sent_at']);
        });

        Schema::table('crm_leads', function (Blueprint $table): void {
            $table->dropColumn(['score_breakdown', 'score_calculated_at']);
        });

        Schema::dropIfExists('crm_lead_scoring_rules');
    }
};
