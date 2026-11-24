<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SQLite rebuilds a table whenever a migration changes a column, and the rebuild recreates every index without its
 * WHERE clause: a partial unique index becomes a plain unique index and rejects rows it must accept (a soft-deleted
 * duplicate, a second document type). This puts the clause back on every partial index that lost it. PostgreSQL keeps
 * them intact and MySQL has no partial indexes, so only SQLite is touched.
 */
return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const array PARTIAL_INDEXES = [
        'assoc_access_areas_code_unique' => 'CREATE UNIQUE INDEX assoc_access_areas_code_unique ON assoc_access_areas (tenant_id, code) WHERE deleted_at IS NULL',
        'assoc_area_presences_open_unique' => 'CREATE UNIQUE INDEX assoc_area_presences_open_unique ON assoc_area_presences (area_id, member_id) WHERE left_at IS NULL',
        'assoc_campaigns_code_unique' => 'CREATE UNIQUE INDEX assoc_campaigns_code_unique ON assoc_campaigns (tenant_id, code) WHERE deleted_at IS NULL',
        'assoc_course_enrolments_unique' => "CREATE UNIQUE INDEX assoc_course_enrolments_unique ON assoc_course_enrolments (course_id, member_id) WHERE deleted_at IS NULL AND status <> 'withdrawn'",
        'assoc_course_instructors_unique' => 'CREATE UNIQUE INDEX assoc_course_instructors_unique ON assoc_course_instructors (course_id, member_id) WHERE deleted_at IS NULL',
        'assoc_custom_fields_code_unique' => 'CREATE UNIQUE INDEX assoc_custom_fields_code_unique ON assoc_custom_fields (tenant_id, code) WHERE deleted_at IS NULL',
        'assoc_donation_receipts_active_unique' => 'CREATE UNIQUE INDEX assoc_donation_receipts_active_unique ON assoc_donation_receipts (donation_id) WHERE cancelled_at IS NULL',
        'assoc_event_registrations_unique' => "CREATE UNIQUE INDEX assoc_event_registrations_unique ON assoc_event_registrations (ticket_type_id, member_id) WHERE deleted_at IS NULL AND status <> 'cancelled'",
        'assoc_expense_categories_unique' => "CREATE UNIQUE INDEX assoc_expense_categories_unique ON assoc_expense_categories (COALESCE(tenant_id, '00000000-0000-0000-0000-000000000000'), scope, code) WHERE deleted_at IS NULL",
        'assoc_fee_plans_code_unique' => 'CREATE UNIQUE INDEX assoc_fee_plans_code_unique ON assoc_fee_plans (tenant_id, code) WHERE deleted_at IS NULL',
        'assoc_guardianships_unique' => 'CREATE UNIQUE INDEX assoc_guardianships_unique ON assoc_guardianships (minor_member_id, guardian_contact_id) WHERE deleted_at IS NULL',
        'assoc_legal_parameters_generic_unique' => 'CREATE UNIQUE INDEX assoc_legal_parameters_generic_unique ON assoc_legal_parameters (code, year) WHERE tenant_id IS NULL AND deleted_at IS NULL',
        'assoc_legal_parameters_tenant_unique' => 'CREATE UNIQUE INDEX assoc_legal_parameters_tenant_unique ON assoc_legal_parameters (tenant_id, code, year) WHERE tenant_id IS NOT NULL AND deleted_at IS NULL',
        'assoc_lex398_entries_source_unique' => 'CREATE UNIQUE INDEX assoc_lex398_entries_source_unique ON assoc_lex398_entries (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL',
        'assoc_meeting_participants_unique' => 'CREATE UNIQUE INDEX assoc_meeting_participants_unique ON assoc_meeting_participants (meeting_id, member_id) WHERE deleted_at IS NULL',
        'assoc_members_contact_unique' => 'CREATE UNIQUE INDEX assoc_members_contact_unique ON assoc_members (tenant_id, contact_id) WHERE deleted_at IS NULL',
        'assoc_members_tax_code_unique' => 'CREATE UNIQUE INDEX assoc_members_tax_code_unique ON assoc_members (tenant_id, tax_code_hash) WHERE tax_code_hash IS NOT NULL AND deleted_at IS NULL',
        'assoc_membership_types_code_unique' => 'CREATE UNIQUE INDEX assoc_membership_types_code_unique ON assoc_membership_types (tenant_id, code) WHERE deleted_at IS NULL',
        'assoc_memberships_card_unique' => 'CREATE UNIQUE INDEX assoc_memberships_card_unique ON assoc_memberships (tenant_id, year_id, card_number) WHERE card_number IS NOT NULL AND deleted_at IS NULL',
        'assoc_memberships_year_unique' => "CREATE UNIQUE INDEX assoc_memberships_year_unique ON assoc_memberships (member_id, year_id, membership_type_id) WHERE deleted_at IS NULL AND status NOT IN ('rejected', 'ceased')",
        'assoc_minutes_meeting_unique' => 'CREATE UNIQUE INDEX assoc_minutes_meeting_unique ON assoc_minutes (meeting_id) WHERE deleted_at IS NULL',
        'assoc_minutes_number_unique' => 'CREATE UNIQUE INDEX assoc_minutes_number_unique ON assoc_minutes (tenant_id, number) WHERE number IS NOT NULL',
        'assoc_mission_reports_year_unique' => 'CREATE UNIQUE INDEX assoc_mission_reports_year_unique ON assoc_mission_reports (tenant_id, year) WHERE deleted_at IS NULL',
        'assoc_network_links_unique' => 'CREATE UNIQUE INDEX assoc_network_links_unique ON assoc_network_links (tenant_id, parent_tenant_id) WHERE deleted_at IS NULL',
        'assoc_shift_assignments_unique' => 'CREATE UNIQUE INDEX assoc_shift_assignments_unique ON assoc_shift_assignments (shift_id, volunteer_id) WHERE deleted_at IS NULL',
        'assoc_statement_mappings_unique' => "CREATE UNIQUE INDEX assoc_statement_mappings_unique ON assoc_statement_mappings (tenant_id, scheme, account_id, COALESCE(cost_center_id, '00000000-0000-0000-0000-000000000000')) WHERE deleted_at IS NULL",
        'assoc_years_code_unique' => 'CREATE UNIQUE INDEX assoc_years_code_unique ON assoc_years (tenant_id, code) WHERE deleted_at IS NULL',
        'commercial_documents_pos_sale_unique' => "CREATE UNIQUE INDEX commercial_documents_pos_sale_unique ON commercial_documents (pos_sale_id) WHERE document_type = 'sale' AND pos_sale_id IS NOT NULL",
        'eam_depr_runs_live_period_unique' => 'CREATE UNIQUE INDEX eam_depr_runs_live_period_unique ON eam_depreciation_runs (tenant_id, period_code, period_type, ledger_type) WHERE deleted_at IS NULL',
        'eam_fixed_assets_active_depreciable_partial_idx' => "CREATE INDEX eam_fixed_assets_active_depreciable_partial_idx ON eam_fixed_assets (tenant_id, company_id, in_service_date) WHERE status IN ('active', 'revalued', 'impaired', 'commissioned') AND is_locked = false AND deleted_at IS NULL",
        'eam_fixed_assets_invoice_line_unique' => 'CREATE UNIQUE INDEX eam_fixed_assets_invoice_line_unique ON eam_fixed_assets (tenant_id, purchase_invoice_line_id) WHERE purchase_invoice_line_id IS NOT NULL AND deleted_at IS NULL',
        'eam_fixed_assets_po_item_unique' => 'CREATE UNIQUE INDEX eam_fixed_assets_po_item_unique ON eam_fixed_assets (tenant_id, purchase_order_item_id) WHERE purchase_order_item_id IS NOT NULL AND deleted_at IS NULL',
        'esm_tickets_active_priority_idx' => "CREATE INDEX esm_tickets_active_priority_idx ON esm_tickets (tenant_id, priority, status) WHERE status IN ('open', 'assigned', 'in_progress') AND deleted_at IS NULL",
        'esm_work_orders_active_schedule_idx' => "CREATE INDEX esm_work_orders_active_schedule_idx ON esm_work_orders (tenant_id, assigned_technician_id, scheduled_start) WHERE status IN ('scheduled', 'dispatched', 'in_progress') AND deleted_at IS NULL",
        'hr_employees_active_partial_idx' => "CREATE INDEX hr_employees_active_partial_idx ON hr_employees (tenant_id, department_id, manager_id) WHERE employment_status IN ('onboarding', 'probation', 'active', 'promoted', 'transferred', 'rehired') AND deleted_at IS NULL",
        'hr_shifts_one_type_a_day' => 'CREATE UNIQUE INDEX hr_shifts_one_type_a_day ON hr_shifts (tenant_id, employee_id, work_date, shift_type_id) WHERE deleted_at IS NULL',
        'payroll_legal_parameters_company' => 'CREATE UNIQUE INDEX payroll_legal_parameters_company ON payroll_legal_parameters (tenant_id, topic, year) WHERE tenant_id IS NOT NULL',
        'payroll_legal_parameters_generic' => 'CREATE UNIQUE INDEX payroll_legal_parameters_generic ON payroll_legal_parameters (topic, year) WHERE tenant_id IS NULL',
        'sub_dunning_records_one_active_per_invoice' => "CREATE UNIQUE INDEX sub_dunning_records_one_active_per_invoice ON sub_dunning_records (invoice_id) WHERE status = 'active'",
        'sub_invoice_schedules_one_recurring_per_period' => "CREATE UNIQUE INDEX sub_invoice_schedules_one_recurring_per_period ON sub_invoice_schedules (subscription_id, service_period_start) WHERE billing_kind = 'recurring' AND status IN ('processing', 'invoiced')",
        'sub_plans_active_idx' => 'CREATE INDEX sub_plans_active_idx ON sub_plans (tenant_id, billing_interval) WHERE is_active = true',
        'sub_subscriptions_active_idx' => "CREATE INDEX sub_subscriptions_active_idx ON sub_subscriptions (tenant_id, status) WHERE status = 'active'",
        'uq_dms_attachment_reference' => 'CREATE UNIQUE INDEX uq_dms_attachment_reference ON dms_document_versions (tenant_id, attachment_id) WHERE attachment_id IS NOT NULL AND deleted_at IS NULL',
        'uq_dms_tag_name' => 'CREATE UNIQUE INDEX uq_dms_tag_name ON dms_tags (tenant_id, lower(name)) WHERE deleted_at IS NULL',
        'uq_journal_entries_vat_settlement' => "CREATE UNIQUE INDEX uq_journal_entries_vat_settlement ON journal_entries (tenant_id, source_document_id) WHERE source_document_type = 'vat_settlement' AND journal_type <> 'reversing'",
        'uq_journal_sales_commission_statement_entry' => "CREATE UNIQUE INDEX uq_journal_sales_commission_statement_entry ON journal_entries (tenant_id, source_document_id) WHERE status = 'posted' AND journal_type <> 'reversing' AND source_document_type = 'sales_commission_statement' AND deleted_at IS NULL",
        'uq_journal_single_document_entry' => "CREATE UNIQUE INDEX uq_journal_single_document_entry ON journal_entries (tenant_id, source_document_type, source_document_id) WHERE status = 'posted' AND journal_type <> 'reversing' AND source_document_type IN ('sales_invoice', 'credit_note', 'vendor_invoice', 'vendor_credit_note', 'customer_payment', 'supplier_payment', 'treasury_payment_line', 'treasury_collection', 'payroll_run') AND deleted_at IS NULL",
        'uq_sales_commissions_invoice' => 'CREATE UNIQUE INDEX uq_sales_commissions_invoice ON sales_commissions (tenant_id, invoice_id) WHERE invoice_id IS NOT NULL AND deleted_at IS NULL',
        'uq_stock_items_tenant_product' => 'CREATE UNIQUE INDEX uq_stock_items_tenant_product ON stock_items (tenant_id, product_id) WHERE product_id IS NOT NULL AND deleted_at IS NULL',
        'visitor_visits_active_badge_unique' => "CREATE UNIQUE INDEX visitor_visits_active_badge_unique ON visitor_visits (tenant_id, badge_number) WHERE status = 'checked_in' AND badge_number IS NOT NULL",
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        foreach (self::PARTIAL_INDEXES as $name => $statement) {
            $existing = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'index' AND name = ?", [$name]);

            if ($existing === null || stripos((string) $existing->sql, ' WHERE ') !== false) {
                continue;
            }

            DB::statement("DROP INDEX \"{$name}\"");
            DB::statement($statement);
        }
    }

    public function down(): void {}
};
