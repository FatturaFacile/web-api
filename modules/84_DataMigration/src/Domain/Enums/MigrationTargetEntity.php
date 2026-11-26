<?php

declare(strict_types=1);

namespace Modules\DataMigration\Domain\Enums;

enum MigrationTargetEntity: string
{
    case ChartOfAccounts = 'ChartOfAccounts';
    case Customers = 'Customers';
    case Vendors = 'Vendors';
    case Products = 'Products';
    case Employees = 'Employees';
    case OpeningGlBalances = 'OpeningGlBalances';
    case OpenArApItems = 'OpenArApItems';
    case HistoricalInvoices = 'HistoricalInvoices';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return list<array{field: string, required: bool, type: string, sample: string}>
     */
    public function schemaFields(): array
    {
        return match ($this) {
            self::ChartOfAccounts => [
                ['field' => 'code', 'required' => true, 'type' => 'string', 'sample' => '1020'],
                ['field' => 'name', 'required' => true, 'type' => 'string', 'sample' => 'Banca UBS CHF'],
                ['field' => 'type', 'required' => true, 'type' => 'enum:asset,liability,equity,revenue,expense', 'sample' => 'asset'],
                ['field' => 'currency', 'required' => false, 'type' => 'currency', 'sample' => 'CHF'],
                ['field' => 'parent_code', 'required' => false, 'type' => 'string', 'sample' => '1000'],
                ['field' => 'standard', 'required' => false, 'type' => 'string', 'sample' => 'ch_kmu'],
            ],
            self::Customers, self::Vendors => [
                ['field' => 'company_name', 'required' => true, 'type' => 'string', 'sample' => 'Acme Sagl'],
                ['field' => 'country', 'required' => true, 'type' => 'country_iso2', 'sample' => 'CH'],
                ['field' => 'vat_number', 'required' => false, 'type' => 'it_vat', 'sample' => ''],
                ['field' => 'fiscal_code', 'required' => false, 'type' => 'it_fiscal_code', 'sample' => ''],
                ['field' => 'uid_number', 'required' => false, 'type' => 'ch_uid', 'sample' => 'CHE-105.805.239'],
                ['field' => 'email', 'required' => false, 'type' => 'email', 'sample' => 'info@acme.ch'],
                ['field' => 'phone', 'required' => false, 'type' => 'string', 'sample' => '+41 91 555 01 00'],
                ['field' => 'iban', 'required' => false, 'type' => 'iban', 'sample' => 'CH9300762011623852957'],
                ['field' => 'qr_iban', 'required' => false, 'type' => 'qr_iban', 'sample' => 'CH4431999123000889012'],
                ['field' => 'sdi_code', 'required' => false, 'type' => 'sdi_code', 'sample' => ''],
                ['field' => 'pec', 'required' => false, 'type' => 'email', 'sample' => ''],
                ['field' => 'street', 'required' => false, 'type' => 'string', 'sample' => 'Via Nassa 21'],
                ['field' => 'postal_code', 'required' => false, 'type' => 'string', 'sample' => '6900'],
                ['field' => 'city', 'required' => false, 'type' => 'string', 'sample' => 'Lugano'],
                ['field' => 'payment_terms_days', 'required' => false, 'type' => 'integer', 'sample' => '30'],
            ],
            self::Products => [
                ['field' => 'sku', 'required' => true, 'type' => 'string', 'sample' => 'SKU-1001'],
                ['field' => 'name', 'required' => true, 'type' => 'string', 'sample' => 'Consulenza ERP'],
                ['field' => 'description', 'required' => false, 'type' => 'string', 'sample' => 'Servizio di consulenza'],
                ['field' => 'unit_measure', 'required' => false, 'type' => 'string', 'sample' => 'H'],
                ['field' => 'sale_price', 'required' => true, 'type' => 'decimal', 'sample' => '150.00'],
                ['field' => 'cost_price', 'required' => false, 'type' => 'decimal', 'sample' => '80.00'],
                ['field' => 'tax_rate', 'required' => false, 'type' => 'decimal', 'sample' => '8.10'],
                ['field' => 'barcode', 'required' => false, 'type' => 'string', 'sample' => '7612345678900'],
            ],
            self::Employees => [
                ['field' => 'email', 'required' => true, 'type' => 'email', 'sample' => 'mario.rossi@acme.ch'],
                ['field' => 'first_name', 'required' => true, 'type' => 'string', 'sample' => 'Mario'],
                ['field' => 'last_name', 'required' => true, 'type' => 'string', 'sample' => 'Rossi'],
                ['field' => 'social_security_number', 'required' => false, 'type' => 'string', 'sample' => '756.1234.5678.90'],
                ['field' => 'fiscal_code', 'required' => false, 'type' => 'it_fiscal_code', 'sample' => ''],
                ['field' => 'job_title', 'required' => false, 'type' => 'string', 'sample' => 'Contabile'],
                ['field' => 'department', 'required' => false, 'type' => 'string', 'sample' => 'Amministrazione'],
                ['field' => 'hire_date', 'required' => true, 'type' => 'date', 'sample' => '2025-01-01'],
                ['field' => 'base_salary', 'required' => false, 'type' => 'decimal', 'sample' => '6500.00'],
                ['field' => 'currency', 'required' => false, 'type' => 'currency', 'sample' => 'CHF'],
                ['field' => 'iban', 'required' => false, 'type' => 'iban', 'sample' => 'CH9300762011623852957'],
            ],
            self::OpeningGlBalances => [
                ['field' => 'entry_date', 'required' => true, 'type' => 'date', 'sample' => '2026-01-01'],
                ['field' => 'account_code', 'required' => true, 'type' => 'account_code', 'sample' => '1020'],
                ['field' => 'description', 'required' => true, 'type' => 'string', 'sample' => 'Saldo apertura Banca'],
                ['field' => 'debit_amount', 'required' => false, 'type' => 'decimal', 'sample' => '10000.00'],
                ['field' => 'credit_amount', 'required' => false, 'type' => 'decimal', 'sample' => '0.00'],
                ['field' => 'currency', 'required' => false, 'type' => 'currency', 'sample' => 'CHF'],
                ['field' => 'document_number', 'required' => false, 'type' => 'string', 'sample' => 'OPEN-2026'],
            ],
            self::OpenArApItems => [
                ['field' => 'ledger_side', 'required' => true, 'type' => 'enum:AR,AP', 'sample' => 'AR'],
                ['field' => 'counterparty_name', 'required' => true, 'type' => 'string', 'sample' => 'Cliente Alpha Srl'],
                ['field' => 'counterparty_tax_id', 'required' => false, 'type' => 'string', 'sample' => '01234560153'],
                ['field' => 'document_number', 'required' => true, 'type' => 'string', 'sample' => 'FT-2025-098'],
                ['field' => 'posting_date', 'required' => true, 'type' => 'date', 'sample' => '2025-12-15'],
                ['field' => 'due_date', 'required' => true, 'type' => 'date', 'sample' => '2026-01-15'],
                ['field' => 'original_amount', 'required' => true, 'type' => 'decimal', 'sample' => '1220.00'],
                ['field' => 'open_amount', 'required' => true, 'type' => 'decimal', 'sample' => '1220.00'],
                ['field' => 'currency', 'required' => false, 'type' => 'currency', 'sample' => 'EUR'],
            ],
            self::HistoricalInvoices => [
                ['field' => 'direction', 'required' => true, 'type' => 'enum:active,passive', 'sample' => 'active'],
                ['field' => 'invoice_number', 'required' => true, 'type' => 'string', 'sample' => 'FT-2025-001'],
                ['field' => 'date', 'required' => true, 'type' => 'date', 'sample' => '2025-03-10'],
                ['field' => 'due_date', 'required' => false, 'type' => 'date', 'sample' => '2025-04-09'],
                ['field' => 'counterparty_name', 'required' => true, 'type' => 'string', 'sample' => 'Beta Srl'],
                ['field' => 'counterparty_vat', 'required' => false, 'type' => 'string', 'sample' => '01234560153'],
                ['field' => 'counterparty_country', 'required' => false, 'type' => 'country_iso2', 'sample' => 'IT'],
                ['field' => 'currency', 'required' => false, 'type' => 'currency', 'sample' => 'EUR'],
                ['field' => 'total_net', 'required' => true, 'type' => 'decimal', 'sample' => '1000.00'],
                ['field' => 'total_tax', 'required' => true, 'type' => 'decimal', 'sample' => '220.00'],
                ['field' => 'total_gross', 'required' => true, 'type' => 'decimal', 'sample' => '1220.00'],
                ['field' => 'line_description', 'required' => false, 'type' => 'string', 'sample' => 'Consulenza tecnica'],
            ],
        };
    }
}
