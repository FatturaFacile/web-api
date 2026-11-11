<?php

declare(strict_types=1);

namespace Modules\Analytics\Semantic;

use Modules\Analytics\Support\ConvertedAmountSql;

/**
 * The datasets shipped with the product. Each one maps to existing tables of the module it belongs to and exposes only
 * the columns listed here: nothing personal (tax codes, salaries, bank details) is in the allow-list. The conditions
 * mirror the ones of the KPIs, so a report and the matching indicator agree: sales are posted invoices without
 * proformas and void documents, purchases are supplier invoices posted to the ledger, ledger lines are the posted
 * statutory ones without opening and closing entries.
 *
 * Amounts of documents are in the accounting currency of the company; a document in another currency without a stored
 * exchange rate cannot be converted and is left out, as in the KPIs.
 */
final readonly class StandardDatasets
{
    private const string ContactName = "COALESCE(NULLIF(contacts.company_name, ''), TRIM(COALESCE(contacts.first_name, '') || ' ' || COALESCE(contacts.last_name, '')))";

    /**
     * Inject the collaborators.
     */
    public function __construct(private ConvertedAmountSql $sql) {}

    /**
     * @return list<Dataset>
     */
    public function all(): array
    {
        return [$this->sales(), $this->purchases(), $this->inventory(), $this->ledger(), $this->projects(), $this->headcount()];
    }

    private function sales(): Dataset
    {
        $rate = $this->sql->rate('invoices');
        $sign = static fn (string $column): string => "(CASE WHEN invoices.invoice_type = 'credit_note' THEN -ABS(invoices.{$column}) ELSE invoices.{$column} END)";

        return new Dataset(
            key: 'sales',
            label: 'Sales',
            description: 'Posted sales invoices and credit notes (credit notes subtract). Proformas, drafts and void documents are not included.',
            module: 'invoicing',
            table: 'invoices',
            joins: [new DatasetJoin('contacts', 'contacts.id = invoices.contact_id', softDeletes: false)],
            conditions: [
                'invoices.posted_at IS NOT NULL',
                "invoices.invoice_type <> 'proforma'",
                "invoices.status NOT IN ('cancelled', 'void')",
                $this->sql->convertible('invoices'),
            ],
            dimensions: [
                new Dimension('date', 'Invoice date', Dimension::TypeDate, 'invoices.date'),
                new Dimension('customer', 'Customer', Dimension::TypeString, self::ContactName, 'contacts'),
                new Dimension('customer_country', 'Customer country', Dimension::TypeString, 'contacts.country', 'contacts'),
                new Dimension('invoice_type', 'Document type', Dimension::TypeString, 'invoices.invoice_type'),
                new Dimension('payment_status', 'Payment status', Dimension::TypeString, 'invoices.payment_status'),
                new Dimension('currency', 'Document currency', Dimension::TypeString, 'UPPER(invoices.currency)'),
                new Dimension('invoice_number', 'Invoice number', Dimension::TypeString, 'invoices.invoice_number'),
            ],
            measures: [
                new Measure('net_amount', 'Net amount', Measure::UnitCurrency, "SUM({$sign('total_net')} * {$rate})"),
                new Measure('tax_amount', 'Tax amount', Measure::UnitCurrency, "SUM({$sign('total_tax')} * {$rate})"),
                new Measure('gross_amount', 'Gross amount', Measure::UnitCurrency, "SUM({$sign('total_gross')} * {$rate})"),
                new Measure('average_net_amount', 'Average net amount per document', Measure::UnitCurrency, "AVG({$sign('total_net')} * {$rate})"),
                new Measure('document_count', 'Documents', Measure::UnitCount, 'COUNT(*)'),
            ],
            periodDimension: 'date',
        );
    }

    private function purchases(): Dataset
    {
        $rate = $this->sql->rate('passive_invoices');
        $sign = static fn (string $column): string => "(CASE WHEN passive_invoices.document_type = 'TD04' THEN -ABS(passive_invoices.{$column}) ELSE passive_invoices.{$column} END)";

        return new Dataset(
            key: 'purchases',
            label: 'Purchases',
            description: 'Supplier invoices posted to the ledger; credit notes (TD04) subtract.',
            module: 'procurement',
            table: 'passive_invoices',
            joins: [new DatasetJoin('contacts', 'contacts.id = passive_invoices.supplier_id', softDeletes: false)],
            conditions: [
                'passive_invoices.gl_journal_entry_id IS NOT NULL',
                $this->sql->convertible('passive_invoices'),
            ],
            dimensions: [
                new Dimension('date', 'Invoice date', Dimension::TypeDate, 'passive_invoices.date'),
                new Dimension('supplier', 'Supplier', Dimension::TypeString, self::ContactName, 'contacts'),
                new Dimension('supplier_country', 'Supplier country', Dimension::TypeString, 'contacts.country', 'contacts'),
                new Dimension('document_type', 'Document type', Dimension::TypeString, 'passive_invoices.document_type'),
                new Dimension('payment_status', 'Payment status', Dimension::TypeString, 'passive_invoices.payment_status'),
                new Dimension('currency', 'Document currency', Dimension::TypeString, 'UPPER(passive_invoices.currency)'),
            ],
            measures: [
                new Measure('net_amount', 'Net amount', Measure::UnitCurrency, "SUM({$sign('total_net')} * {$rate})"),
                new Measure('tax_amount', 'Tax amount', Measure::UnitCurrency, "SUM({$sign('total_tax')} * {$rate})"),
                new Measure('document_count', 'Documents', Measure::UnitCount, 'COUNT(*)'),
            ],
            periodDimension: 'date',
        );
    }

    private function inventory(): Dataset
    {
        return new Dataset(
            key: 'inventory',
            label: 'Inventory',
            description: 'Stock on hand today, valued at the moving average cost of the item. It describes the present and has no period.',
            module: 'inventory',
            table: 'stock_quants',
            joins: [new DatasetJoin('stock_items', 'stock_items.id = stock_quants.stock_item_id', inner: true)],
            conditions: ['stock_quants.quantity_on_hand > 0'],
            dimensions: [
                new Dimension('sku', 'SKU', Dimension::TypeString, 'stock_items.sku', 'stock_items'),
                new Dimension('item', 'Item', Dimension::TypeString, 'stock_items.name', 'stock_items'),
                new Dimension('uom', 'Unit of measure', Dimension::TypeString, 'stock_items.uom', 'stock_items'),
                new Dimension('abc_class', 'ABC class', Dimension::TypeString, 'stock_items.abc_class', 'stock_items'),
                new Dimension('tracking_type', 'Tracking', Dimension::TypeString, 'stock_items.tracking_type', 'stock_items'),
                new Dimension('is_active', 'Item active', Dimension::TypeBoolean, 'stock_items.is_active', 'stock_items'),
            ],
            measures: [
                new Measure('quantity_on_hand', 'Quantity on hand', Measure::UnitQuantity, 'SUM(stock_quants.quantity_on_hand)'),
                new Measure('quantity_reserved', 'Quantity reserved', Measure::UnitQuantity, 'SUM(stock_quants.quantity_reserved)'),
                new Measure('stock_value', 'Stock value', Measure::UnitCurrency, 'SUM(stock_quants.quantity_on_hand * stock_items.current_moving_average_cost)', 'stock_items'),
                new Measure('item_count', 'Items', Measure::UnitCount, 'COUNT(DISTINCT stock_quants.stock_item_id)'),
            ],
        );
    }

    private function ledger(): Dataset
    {
        return new Dataset(
            key: 'ledger',
            label: 'General ledger',
            description: 'Posted lines of the statutory ledger, opening and closing entries excluded, in the functional currency.',
            module: 'ledger',
            table: 'journal_lines',
            joins: [
                new DatasetJoin('journal_entries', 'journal_entries.id = journal_lines.journal_entry_id', inner: true),
                new DatasetJoin('chart_of_accounts', 'chart_of_accounts.id = journal_lines.account_id', inner: true),
            ],
            conditions: [
                "journal_entries.status IN ('posted', 'reversed')",
                "journal_entries.ledger_type = 'statutory'",
                "journal_entries.journal_type NOT IN ('opening', 'closing')",
                "(journal_entries.source_document_type IS NULL OR journal_entries.source_document_type NOT IN ('opening_balance', 'year_end_closing'))",
            ],
            dimensions: [
                new Dimension('posting_date', 'Posting date', Dimension::TypeDate, 'journal_entries.posting_date', 'journal_entries'),
                new Dimension('account_code', 'Account code', Dimension::TypeString, 'chart_of_accounts.account_code', 'chart_of_accounts'),
                new Dimension('account_name', 'Account', Dimension::TypeString, 'chart_of_accounts.name', 'chart_of_accounts'),
                new Dimension('account_type', 'Account type', Dimension::TypeString, 'chart_of_accounts.account_type', 'chart_of_accounts'),
                new Dimension('journal_type', 'Journal type', Dimension::TypeString, 'journal_entries.journal_type', 'journal_entries'),
                new Dimension('entry_number', 'Entry number', Dimension::TypeString, 'journal_entries.entry_number', 'journal_entries'),
            ],
            measures: [
                new Measure('debit', 'Debit', Measure::UnitCurrency, 'SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount))'),
                new Measure('credit', 'Credit', Measure::UnitCurrency, 'SUM(COALESCE(journal_lines.functional_credit, journal_lines.credit_amount))'),
                new Measure('balance', 'Debit minus credit', Measure::UnitCurrency, 'SUM(COALESCE(journal_lines.functional_debit, journal_lines.debit_amount) - COALESCE(journal_lines.functional_credit, journal_lines.credit_amount))'),
                new Measure('line_count', 'Lines', Measure::UnitCount, 'COUNT(*)'),
            ],
            periodDimension: 'posting_date',
        );
    }

    private function projects(): Dataset
    {
        return new Dataset(
            key: 'projects',
            label: 'Projects',
            description: 'Projects with their contract value (in the currency of each project: group by currency before summing) and progress. The period applies to the planned start date.',
            module: 'projects',
            table: 'projects',
            joins: [new DatasetJoin('contacts', 'contacts.id = projects.contact_id', softDeletes: false)],
            conditions: [],
            dimensions: [
                new Dimension('planned_start_date', 'Planned start', Dimension::TypeDate, 'projects.planned_start_date'),
                new Dimension('project_number', 'Project number', Dimension::TypeString, 'projects.project_number'),
                new Dimension('project', 'Project', Dimension::TypeString, 'projects.name'),
                new Dimension('customer', 'Customer', Dimension::TypeString, self::ContactName, 'contacts'),
                new Dimension('status', 'Status', Dimension::TypeString, 'projects.status'),
                new Dimension('project_type', 'Type', Dimension::TypeString, 'projects.project_type'),
                new Dimension('health_status', 'Health', Dimension::TypeString, 'projects.health_status'),
                new Dimension('currency', 'Currency', Dimension::TypeString, 'UPPER(projects.currency)'),
            ],
            measures: [
                new Measure('contract_value', 'Contract value', Measure::UnitCurrency, 'SUM(projects.contract_value)'),
                new Measure('average_progress', 'Average progress', Measure::UnitPercent, 'AVG(projects.progress_percentage)'),
                new Measure('project_count', 'Projects', Measure::UnitCount, 'COUNT(*)'),
            ],
            periodDimension: 'planned_start_date',
        );
    }

    private function headcount(): Dataset
    {
        $active = '(hr_employees.termination_date IS NULL OR hr_employees.termination_date > CURRENT_DATE)';

        return new Dataset(
            key: 'hr_headcount',
            label: 'HR headcount',
            description: 'Employees by department, status and type. Only organisational data: no salary, no personal or contact details. Active means not terminated at the day of the report.',
            module: 'hr',
            table: 'hr_employees',
            joins: [new DatasetJoin('hr_departments', 'hr_departments.id = hr_employees.department_id')],
            conditions: ["hr_employees.employment_status <> 'candidate'"],
            dimensions: [
                new Dimension('department', 'Department', Dimension::TypeString, "COALESCE(hr_departments.name, '-')", 'hr_departments'),
                new Dimension('employment_status', 'Employment status', Dimension::TypeString, 'hr_employees.employment_status'),
                new Dimension('employment_type', 'Employment type', Dimension::TypeString, 'hr_employees.employment_type'),
                new Dimension('hire_date', 'Hire date', Dimension::TypeDate, 'hr_employees.hire_date'),
                new Dimension('termination_date', 'Termination date', Dimension::TypeDate, 'hr_employees.termination_date'),
            ],
            measures: [
                new Measure('employee_count', 'Employees (all records)', Measure::UnitCount, 'COUNT(*)'),
                new Measure('active_headcount', 'Active headcount', Measure::UnitCount, "COUNT(*) FILTER (WHERE {$active})"),
                new Measure('active_fte', 'Active FTE', Measure::UnitQuantity, "COALESCE(SUM(hr_employees.fte_ratio) FILTER (WHERE {$active}), 0)"),
            ],
        );
    }
}
