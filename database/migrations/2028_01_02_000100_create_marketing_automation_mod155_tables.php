<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 155 — Marketing Automation, Newsletter DEM, RFM segmentation and omnichannel campaigns with ROI tracking.
 * Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    private function base(Blueprint $table, bool $nullableTenant = false): void
    {
        $table->uuid('id')->primary();
        $tenant = $table->foreignUuid('tenant_id');
        if ($nullableTenant) {
            $tenant->nullable();
        }
        $tenant->constrained('tenants')->cascadeOnDelete();
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    public function up(): void
    {
        if (! Schema::hasTable('ma_blueprints')) {
            Schema::create('ma_blueprints', function (Blueprint $table): void {
                $this->base($table, true);
                $table->string('kind', 30); // segment_preset | email_template | journey | text
                $table->string('code', 80);
                $table->json('texts')->nullable(); // {it: {...}, de: {...}, fr: {...}, en: {...}}
                $table->json('payload')->nullable();
                $table->boolean('is_active')->default(true);
                $this->audit($table);
                $table->unique(['tenant_id', 'kind', 'code'], 'ma_blueprints_kind_code_uq');
            });
        }

        if (! Schema::hasTable('ma_segments')) {
            Schema::create('ma_segments', function (Blueprint $table): void {
                $this->base($table);
                $table->string('code', 80)->nullable();
                $table->string('name', 160);
                $table->text('description')->nullable();
                $table->json('criteria')->nullable();
                $table->boolean('auto_refresh_hourly')->default(false);
                $table->unsignedInteger('eligible_contacts_count')->default(0);
                $table->unsignedInteger('excluded_insolvent_count')->default(0);
                $table->unsignedInteger('excluded_no_consent_count')->default(0);
                $table->json('rfm_breakdown')->nullable();
                $table->timestamp('last_evaluated_at')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'auto_refresh_hourly'], 'ma_segments_refresh_idx');
            });
        }

        if (! Schema::hasTable('ma_segment_members')) {
            Schema::create('ma_segment_members', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('segment_id')->constrained('ma_segments')->cascadeOnDelete();
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->unsignedTinyInteger('r_score');
                $table->unsignedTinyInteger('f_score');
                $table->unsignedTinyInteger('m_score');
                $table->string('rfm_label', 30);
                $table->string('locale', 2)->default('it');
                $table->unsignedInteger('recency_days')->nullable();
                $table->unsignedInteger('frequency_orders')->default(0);
                $table->decimal('monetary_value', 15, 2)->default(0);
                $this->audit($table);
                $table->unique(['segment_id', 'contact_id'], 'ma_seg_members_uq');
            });
        }

        if (! Schema::hasTable('ma_campaigns')) {
            Schema::create('ma_campaigns', function (Blueprint $table): void {
                $this->base($table);
                $table->string('name', 160);
                $table->string('status', 20)->default('draft');
                $table->string('channel', 40)->default('email_dem');
                $table->string('trigger_type', 40)->default('scheduled_broadcast');
                $table->foreignUuid('segment_id')->nullable()->constrained('ma_segments')->nullOnDelete();
                $table->string('sender_name', 120)->nullable();
                $table->string('sender_email', 190)->nullable();
                $table->string('sender_postal_address', 255)->nullable();
                $table->string('provider', 30)->default('smtp');
                $table->json('template_mjml_json')->nullable();
                $table->json('ab_test_split_variant_json')->nullable();
                $table->string('utm_campaign_code', 80)->nullable();
                $table->string('attribution_model', 20)->default('last_touch');
                $table->unsignedSmallInteger('attribution_window_days')->default(30);
                $table->string('currency', 3)->default('CHF');
                $table->decimal('campaign_cost', 15, 2)->default(0);
                $table->string('coupon_code', 60)->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $this->audit($table);
                $table->index(['tenant_id', 'status'], 'ma_campaigns_status_idx');
                $table->index(['tenant_id', 'trigger_type'], 'ma_campaigns_trigger_idx');
            });
        }

        if (! Schema::hasTable('ma_journey_steps')) {
            Schema::create('ma_journey_steps', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('campaign_id')->constrained('ma_campaigns')->cascadeOnDelete();
                $table->string('step_key', 60);
                $table->string('type', 30); // send_email | send_whatsapp | send_sms | wait | condition | crm_task | end
                $table->json('config')->nullable();
                $table->string('next_step_key', 60)->nullable();
                $table->string('true_step_key', 60)->nullable();
                $table->string('false_step_key', 60)->nullable();
                $table->boolean('is_entry')->default(false);
                $table->integer('pos_x')->default(0);
                $table->integer('pos_y')->default(0);
                $this->audit($table);
                $table->unique(['campaign_id', 'step_key'], 'ma_steps_campaign_key_uq');
            });
        }

        if (! Schema::hasTable('ma_enrollments')) {
            Schema::create('ma_enrollments', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('campaign_id')->constrained('ma_campaigns')->cascadeOnDelete();
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('current_step_key', 60)->nullable();
                $table->string('status', 20)->default('active'); // active | completed | stopped
                $table->string('variant', 20)->nullable();
                $table->timestamp('next_run_at')->nullable();
                $this->audit($table);
                $table->unique(['campaign_id', 'contact_id'], 'ma_enroll_uq');
                $table->index(['status', 'next_run_at'], 'ma_enroll_due_idx');
            });
        }

        if (! Schema::hasTable('ma_step_logs')) {
            Schema::create('ma_step_logs', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('enrollment_id')->constrained('ma_enrollments')->cascadeOnDelete();
                $table->foreignUuid('campaign_id')->constrained('ma_campaigns')->cascadeOnDelete();
                $table->string('step_key', 60);
                $table->string('type', 30);
                $table->string('outcome', 30);
                $table->text('detail')->nullable();
                $table->timestamp('executed_at');
                $this->audit($table);
            });
        }

        if (! Schema::hasTable('ma_messages')) {
            Schema::create('ma_messages', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('campaign_id')->constrained('ma_campaigns')->cascadeOnDelete();
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('token', 64)->unique();
                $table->string('channel', 20)->default('email');
                $table->string('recipient', 190);
                $table->string('variant', 20)->nullable();
                $table->string('locale', 2)->default('it');
                $table->string('status', 20)->default('queued');
                $table->string('provider_reference', 190)->nullable();
                $table->text('error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('first_opened_at')->nullable();
                $table->timestamp('first_clicked_at')->nullable();
                $table->timestamp('bounced_at')->nullable();
                $this->audit($table);
                $table->index(['campaign_id', 'status'], 'ma_messages_campaign_idx');
                $table->index(['tenant_id', 'contact_id', 'sent_at'], 'ma_messages_contact_idx');
            });
        }

        if (! Schema::hasTable('ma_metrics')) {
            Schema::create('ma_metrics', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('campaign_id')->constrained('ma_campaigns')->cascadeOnDelete();
                foreach (['sent', 'delivered', 'unique_opens', 'unique_clicks', 'unsubscribes', 'hard_bounces', 'soft_bounces', 'spam_complaints'] as $counter) {
                    $table->unsignedInteger($counter.'_count')->default(0);
                }
                $table->unsignedInteger('attributed_quotes_count')->default(0);
                $table->decimal('attributed_quotes_value', 15, 2)->default(0);
                $table->unsignedInteger('attributed_sales_orders_count')->default(0);
                $table->decimal('attributed_net_revenue_amount', 15, 2)->default(0);
                $table->decimal('attributed_invoiced_amount', 15, 2)->default(0);
                $table->decimal('attributed_collected_amount', 15, 2)->default(0);
                $table->decimal('attributed_gross_margin_amount', 15, 2)->default(0);
                $table->decimal('campaign_cost', 15, 2)->default(0);
                $table->decimal('calculated_roi_percentage', 10, 2)->nullable();
                $table->string('roi_state', 20)->default('no_cost'); // computed | no_cost | no_revenue
                $table->string('currency', 3)->default('CHF');
                $table->timestamp('calculated_at')->nullable();
                $this->audit($table);
                $table->unique('campaign_id', 'ma_metrics_campaign_uq');
            });
        }

        if (! Schema::hasTable('ma_attributions')) {
            Schema::create('ma_attributions', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('campaign_id')->constrained('ma_campaigns')->cascadeOnDelete();
                $table->foreignUuid('message_id')->nullable()->constrained('ma_messages')->nullOnDelete();
                $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('document_type', 20); // quote | sales_order | invoice
                $table->uuid('document_id');
                $table->string('touch_model', 20);
                $table->string('currency', 3);
                $table->decimal('amount_net', 15, 2)->default(0);
                $table->decimal('amount_gross', 15, 2)->default(0);
                $table->decimal('gross_margin', 15, 2)->default(0);
                $table->decimal('collected_amount', 15, 2)->default(0);
                $table->string('via', 20)->default('touch'); // touch | coupon
                $table->timestamp('attributed_at');
                $this->audit($table);
                $table->unique(['tenant_id', 'document_type', 'document_id'], 'ma_attr_doc_uq');
                $table->index(['campaign_id', 'document_type'], 'ma_attr_campaign_idx');
            });
        }

        if (! Schema::hasTable('ma_consents')) {
            Schema::create('ma_consents', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->boolean('marketing_opt_in')->default(false);
                $table->boolean('profiling_opt_in')->default(false);
                $table->boolean('soft_opt_in_eligible')->default(false);
                $table->boolean('double_opt_in_confirmed')->default(false);
                $table->json('channel_preferences')->nullable();
                $table->string('locale', 2)->default('it');
                $table->timestamp('unsubscribed_at')->nullable();
                $this->audit($table);
                $table->unique(['tenant_id', 'contact_id'], 'ma_consents_contact_uq');
            });
        }

        if (! Schema::hasTable('ma_consent_events')) {
            Schema::create('ma_consent_events', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('contact_id')->constrained('contacts')->cascadeOnDelete();
                $table->string('event_type', 40);
                $table->string('lawful_basis', 40)->nullable();
                $table->string('strategy_code', 60)->nullable();
                $table->string('source', 60)->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->json('details')->nullable();
                $table->timestamp('occurred_at');
                $this->audit($table);
                $table->index(['tenant_id', 'contact_id', 'occurred_at'], 'ma_consent_events_idx');
            });
        }

        if (! Schema::hasTable('ma_suppressions')) {
            Schema::create('ma_suppressions', function (Blueprint $table): void {
                $this->base($table);
                $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('address', 190);
                $table->string('channel', 20)->default('email');
                $table->string('reason', 30); // hard_bounce | spam_complaint | unsubscribe
                $table->uuid('campaign_id')->nullable();
                $table->timestamp('suppressed_at');
                $this->audit($table);
                $table->unique(['tenant_id', 'channel', 'address'], 'ma_suppressions_addr_uq');
            });
        }
    }

    public function down(): void
    {
        foreach (['ma_suppressions', 'ma_consent_events', 'ma_consents', 'ma_attributions', 'ma_metrics', 'ma_messages', 'ma_step_logs', 'ma_enrollments', 'ma_journey_steps', 'ma_campaigns', 'ma_segment_members', 'ma_segments', 'ma_blueprints'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
