<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mod 299 — Adventure parks, climbing centres and educational farms: timed slots on harness stock per size, PPE register
 * (UNI EN 15567 / EN 365), liability waivers and school trips. Tenant isolation is enforced by the BelongsToTenant global scope
 * (every table has a nullable tenant_id). Dialect-agnostic: runs identically on SQLite and PostgreSQL.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const array TABLES = [
        'adv_school_booking_workshops', 'adv_school_bookings', 'adv_educational_workshops', 'adv_waiver_templates', 'adv_safety_waivers',
        'adv_ticket_ppe_assignments', 'adv_ticket_participants', 'adv_ticket_lines', 'adv_tickets', 'adv_daily_course_checks',
        'adv_ppe_inspection_logs', 'adv_ppe_assets', 'adv_slot_templates', 'adv_park_configs',
    ];

    public function up(): void
    {
        $this->parkConfigs();
        $this->slotTemplates();
        $this->ppeAssets();
        $this->inspectionLogs();
        $this->dailyChecks();
        $this->tickets();
        $this->waivers();
        $this->workshops();
        $this->schoolBookings();
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function audit(Blueprint $table): void
    {
        $table->uuid('created_by')->nullable();
        $table->uuid('updated_by')->nullable();
        $table->uuid('deleted_by')->nullable();
        $table->timestamps();
        $table->softDeletes();
    }

    private function parkConfigs(): void
    {
        if (Schema::hasTable('adv_park_configs')) {
            return;
        }

        Schema::create('adv_park_configs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->unsignedSmallInteger('buffer_minutes')->default(15);
            $table->unsignedSmallInteger('teacher_free_ratio')->default(15);
            $table->unsignedSmallInteger('teacher_free_per_disabled')->default(1);
            $table->unsignedSmallInteger('picnic_capacity')->default(60);
            $table->string('waiver_version_id', 20)->default('v1');
            $table->json('wristband_palette')->nullable();
            $this->audit($table);

            $table->index('tenant_id', 'adv_pc_tenant_ix');
        });
    }

    private function slotTemplates(): void
    {
        if (Schema::hasTable('adv_slot_templates')) {
            return;
        }

        Schema::create('adv_slot_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('start_time', 5);
            $table->string('end_time', 5);
            $table->string('wristband_color', 20);
            $table->json('weekdays')->nullable(); // ISO weekdays 1..7, null = every day
            $table->boolean('is_active')->default(true);
            $this->audit($table);

            $table->index(['tenant_id', 'is_active'], 'adv_st_active_ix');
        });
    }

    private function ppeAssets(): void
    {
        if (Schema::hasTable('adv_ppe_assets')) {
            return;
        }

        Schema::create('adv_ppe_assets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('serial_code', 60);
            $table->string('category', 30); // HARNESS, LANYARD_LONGE, CARABINER, PULLEY, ENERGY_ABSORBER, HELMET, COURSE_CABLE
            $table->string('size', 15)->default('UNIVERSAL'); // BABY, JUNIOR, ADULT, XL, UNIVERSAL
            $table->unsignedSmallInteger('min_height_cm')->nullable();
            $table->unsignedSmallInteger('max_height_cm')->nullable();
            $table->unsignedSmallInteger('min_weight_kg')->nullable();
            $table->unsignedSmallInteger('max_weight_kg')->nullable();
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('batch_number', 60)->nullable();
            $table->date('purchase_date')->nullable();
            $table->date('first_use_date')->nullable();
            $table->date('manufacturer_max_lifespan_date');
            $table->unsignedInteger('rental_cycle_count')->default(0);
            $table->decimal('cumulative_usage_hours', 10, 2)->default(0);
            $table->date('last_inspection_date')->nullable();
            $table->date('next_inspection_due_date')->nullable();
            $table->string('status', 30)->default('AVAILABLE'); // AVAILABLE, IN_USE, MAINTENANCE_REQUIRED, EXPIRED_BLOCKED, DECOMMISSIONED
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->unique(['tenant_id', 'serial_code'], 'adv_ppe_serial_uq');
            $table->index(['tenant_id', 'category', 'size', 'status'], 'adv_ppe_stock_ix');
        });
    }

    private function inspectionLogs(): void
    {
        if (Schema::hasTable('adv_ppe_inspection_logs')) {
            return;
        }

        Schema::create('adv_ppe_inspection_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('ppe_asset_id')->constrained('adv_ppe_assets')->cascadeOnDelete();
            $table->string('inspection_type', 20); // DAILY_VISUAL, ANNUAL_EN365, INCIDENT
            $table->string('outcome', 10); // PASS, FAIL
            $table->date('inspected_on');
            $table->string('inspector_name', 150)->nullable();
            $table->string('inspector_license_number', 60)->nullable();
            $table->text('signature')->nullable();
            $table->text('notes')->nullable();
            $table->json('checklist')->nullable();
            $table->date('next_due_date')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'ppe_asset_id', 'inspected_on'], 'adv_pil_asset_ix');
        });
    }

    private function dailyChecks(): void
    {
        if (Schema::hasTable('adv_daily_course_checks')) {
            return;
        }

        Schema::create('adv_daily_course_checks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->date('check_date');
            $table->string('checked_by_name', 150);
            $table->json('items');
            $table->string('outcome', 10); // PASS, FAIL
            $table->text('signature')->nullable();
            $table->text('notes')->nullable();
            $this->audit($table);

            $table->index(['tenant_id', 'check_date'], 'adv_dcc_date_ix');
        });
    }

    private function tickets(): void
    {
        if (! Schema::hasTable('adv_tickets')) {
            Schema::create('adv_tickets', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('slot_template_id')->nullable()->constrained('adv_slot_templates')->nullOnDelete();
                $table->string('booking_code', 20);
                $table->string('public_token', 64);
                $table->date('slot_date');
                $table->string('start_time', 5);
                $table->string('end_time', 5);
                $table->string('wristband_color', 20);
                $table->string('contact_name', 150);
                $table->string('contact_email', 190)->nullable();
                $table->string('contact_phone', 40)->nullable();
                $table->unsignedSmallInteger('headcount');
                $table->string('payment_status', 15)->default('PENDING'); // PENDING, PAID, REFUNDED
                $table->string('status', 20)->default('BOOKED'); // BOOKED, WAIVER_SIGNED, EQUIPPED, ON_COURSE, RETURNED, NO_SHOW
                $this->audit($table);

                $table->unique(['tenant_id', 'booking_code'], 'adv_tk_code_uq');
                $table->unique('public_token', 'adv_tk_token_uq');
                $table->index(['tenant_id', 'slot_date', 'status'], 'adv_tk_day_ix');
            });
        }

        if (! Schema::hasTable('adv_ticket_lines')) {
            Schema::create('adv_ticket_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('ticket_id')->constrained('adv_tickets')->cascadeOnDelete();
                $table->string('size', 15);
                $table->unsignedSmallInteger('quantity');
                $this->audit($table);

                $table->index(['tenant_id', 'ticket_id'], 'adv_tl_ticket_ix');
            });
        }

        if (! Schema::hasTable('adv_ticket_participants')) {
            Schema::create('adv_ticket_participants', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('ticket_id')->constrained('adv_tickets')->cascadeOnDelete();
                $table->string('full_name', 150);
                $table->boolean('is_minor')->default(false);
                $table->boolean('is_signer')->default(false);
                $table->date('birth_date')->nullable();
                $table->decimal('height_cm', 5, 1)->nullable();
                $table->decimal('weight_kg', 5, 1)->nullable();
                $table->string('size', 15)->nullable();
                $table->string('fiscal_code', 20)->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('email', 190)->nullable();
                $this->audit($table);

                $table->index(['tenant_id', 'ticket_id'], 'adv_tp_ticket_ix');
            });
        }

        if (! Schema::hasTable('adv_ticket_ppe_assignments')) {
            Schema::create('adv_ticket_ppe_assignments', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('ticket_id')->constrained('adv_tickets')->cascadeOnDelete();
                $table->foreignUuid('participant_id')->constrained('adv_ticket_participants')->cascadeOnDelete();
                $table->foreignUuid('ppe_asset_id')->constrained('adv_ppe_assets')->restrictOnDelete();
                $table->timestamp('assigned_at');
                $table->timestamp('returned_at')->nullable();
                $table->decimal('usage_hours', 8, 2)->nullable();
                $this->audit($table);

                $table->index(['tenant_id', 'ticket_id'], 'adv_ta_ticket_ix');
                $table->index(['tenant_id', 'ppe_asset_id', 'returned_at'], 'adv_ta_asset_ix');
            });
        }
    }

    private function waivers(): void
    {
        if (! Schema::hasTable('adv_safety_waivers')) {
            Schema::create('adv_safety_waivers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('ticket_id')->constrained('adv_tickets')->cascadeOnDelete();
                $table->string('signer_full_name', 150);
                $table->string('signer_fiscal_code', 20)->nullable();
                $table->string('signer_phone', 40)->nullable();
                $table->string('signer_email', 190)->nullable();
                $table->date('signer_birth_date')->nullable();
                $table->decimal('signer_height_cm', 5, 1)->nullable();
                $table->decimal('signer_weight_kg', 5, 1)->nullable();
                $table->text('signature_data');
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('locale', 5)->default('it');
                $table->string('waiver_version_id', 20);
                $table->boolean('accepted_rules')->default(false);
                $table->timestamp('signed_at');
                $table->timestamp('briefing_completed_at')->nullable();
                $table->uuid('briefing_instructor_id')->nullable();
                $this->audit($table);

                $table->unique(['tenant_id', 'ticket_id'], 'adv_sw_ticket_uq');
            });
        }

        if (! Schema::hasTable('adv_waiver_templates')) {
            Schema::create('adv_waiver_templates', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->string('version_id', 20);
                $table->string('locale', 5);
                $table->string('title', 200);
                $table->json('paragraphs');
                $table->json('rules');
                $this->audit($table);

                $table->unique(['tenant_id', 'version_id', 'locale'], 'adv_wt_version_uq');
            });
        }
    }

    private function workshops(): void
    {
        if (Schema::hasTable('adv_educational_workshops')) {
            return;
        }

        Schema::create('adv_educational_workshops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->string('code', 40);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('max_participants');
            $table->decimal('price_per_student', 10, 2)->default(0);
            $table->json('name_translations')->nullable();
            $table->json('description_translations')->nullable();
            $table->boolean('is_active')->default(true);
            $this->audit($table);

            $table->unique(['tenant_id', 'code'], 'adv_ew_code_uq');
        });
    }

    private function schoolBookings(): void
    {
        if (! Schema::hasTable('adv_school_bookings')) {
            Schema::create('adv_school_bookings', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('customer_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
                $table->string('institute_name', 200);
                $table->string('mechanographic_code', 20)->nullable();
                $table->string('ipa_office_code', 10);
                $table->string('cig_code', 10)->nullable();
                $table->string('cup_code', 15)->nullable();
                $table->boolean('split_payment')->default(true);
                $table->date('visit_date');
                $table->string('start_time', 5);
                $table->string('end_time', 5);
                $table->boolean('picnic_reserved')->default(false);
                $table->unsignedSmallInteger('paying_students');
                $table->unsignedSmallInteger('disabled_students')->default(0);
                $table->unsignedSmallInteger('accompanying_teachers')->default(0);
                $table->unsignedSmallInteger('free_teachers')->default(0);
                $table->unsignedSmallInteger('charged_teachers')->default(0);
                $table->unsignedSmallInteger('picnic_seats')->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->decimal('vat_rate', 5, 2)->default(10);
                $table->string('status', 15)->default('DRAFT'); // DRAFT, CONFIRMED, COMPLETED, INVOICED
                $table->foreignUuid('fattura_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->string('fattura_number', 50)->nullable();
                $table->timestamp('invoiced_at')->nullable();
                $this->audit($table);

                $table->index(['tenant_id', 'status'], 'adv_sb_status_ix');
                $table->index(['tenant_id', 'visit_date'], 'adv_sb_date_ix');
            });
        }

        if (! Schema::hasTable('adv_school_booking_workshops')) {
            Schema::create('adv_school_booking_workshops', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
                $table->foreignUuid('school_booking_id')->constrained('adv_school_bookings')->cascadeOnDelete();
                $table->foreignUuid('workshop_id')->constrained('adv_educational_workshops')->restrictOnDelete();
                $table->unsignedSmallInteger('quantity_students');
                $table->decimal('unit_price', 10, 2)->default(0);
                $this->audit($table);

                $table->index(['tenant_id', 'school_booking_id'], 'adv_sbw_booking_ix');
            });
        }
    }
};
