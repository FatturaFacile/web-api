<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

/**
 * Registry of the tenant column policy of the schema.
 *
 * Owner policy: every table has a nullable UUID tenant_id. NULL means "global" (visible to every tenant) and is used
 * only for shared data (lookups, translations, catalogues, platform settings, legal reference data); every record that
 * belongs to a tenant carries its tenant_id. The tables below are the only exclusions: they are exempt from the rule
 * (they need no tenant_id, and a tenant_id they happen to have is not checked).
 *
 * The migration that applies the policy and the architecture tests read this class, so a new table without a nullable
 * tenant_id fails the tests until it gets the column or is listed here with its reason.
 */
final class TenantSchemaPolicy
{
    /**
     * Tables exempt from the nullable tenant_id rule, with the reason.
     *
     * @var array<string, string>
     */
    public const EXCLUDED_TABLES = [
        'cache' => 'framework cache store',
        'cache_locks' => 'framework cache store',
        'failed_jobs' => 'framework queue (the tenant travels in the job payload)',
        'job_batches' => 'framework queue',
        'jobs' => 'framework queue (the tenant travels in the job payload)',
        'migrations' => 'framework migration log',
        'notifications' => 'framework notifications, addressed to a user (users are central accounts); owner to confirm',
        'password_reset_tokens' => 'framework password reset, keyed by e-mail of a central account',
        'personal_access_tokens' => 'framework API tokens, owned by a central user account',
        'sessions' => 'framework sessions',
        'tenants' => 'the tenants themselves',
        'users' => 'central accounts, linked to tenants through tenant_user (a user belongs to many tenants); owner to confirm',
    ];

    /**
     * Child tables reached through a parent that carries the tenant: the column is stamped from the parent on creation
     * and backfilled from it by the migration. child table => [parent table, foreign key to the parent].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CHILD_TABLES = [
        'bom_items' => ['bills_of_materials', 'bill_of_materials_id'],
        'contact_addresses' => ['contacts', 'contact_id'],
        'delivery_note_items' => ['delivery_notes', 'delivery_note_id'],
        'expense_items' => ['expense_reports', 'expense_report_id'],
        'general_ledger_lines' => ['general_ledger_entries', 'general_ledger_entry_id'],
        'goods_receipt_items' => ['goods_receipts', 'goods_receipt_id'],
        'invoice_items' => ['invoices', 'invoice_id'],
        'invoice_qr_bill_data' => ['invoices', 'invoice_id'],
        'invoice_sdi_metadata' => ['invoices', 'invoice_id'],
        'payment_term_installments' => ['payment_terms', 'payment_term_id'],
        'pos_sale_items' => ['pos_sales', 'pos_sale_id'],
        'price_list_items' => ['price_lists', 'price_list_id'],
        'production_order_stages' => ['production_orders', 'production_order_id'],
        'purchase_order_items' => ['purchase_orders', 'purchase_order_id'],
        'quote_items' => ['quotes', 'quote_id'],
        'rt_fiscal_receipts' => ['pos_sales', 'pos_sale_id'],
        'sales_order_items' => ['sales_orders', 'sales_order_id'],
        'subscription_items' => ['subscriptions', 'subscription_id'],
        'work_order_lines' => ['work_orders', 'work_order_id'],
    ];

    public static function isExcluded(string $table): bool
    {
        return array_key_exists($table, self::EXCLUDED_TABLES);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function parentOf(string $table): ?array
    {
        return self::CHILD_TABLES[$table] ?? null;
    }
}
