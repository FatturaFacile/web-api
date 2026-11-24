<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\GeneralLedger\Models\AccountingTemplate;

/**
 * Global system templates (causali contabili) for Swiss and Italian prima nota workflows. Accounts are placeholders
 * (`$key`) the user binds to an account of the tenant chart when the template is filled, so the same causale serves
 * the Swiss (OR/FER) and the Italian (OIC) chart. Names are given in it, de, fr and en.
 */
final class AccountingTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $definition) {
            AccountingTemplate::updateOrCreateGlobal(['code' => $definition['code']], [
                'category' => $definition['category'],
                'journal_type' => 'standard',
                'ledger_type' => $definition['ledger_type'] ?? 'statutory',
                'names' => $definition['names'],
                'descriptions' => $definition['descriptions'],
                'parameters' => $definition['parameters'],
                'lines' => $definition['lines'],
                'is_system' => true,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        return [
            [
                'code' => 'PAYROLL_ALLOCATION',
                'category' => 'payroll',
                'names' => ['it' => 'Giroconto stipendi', 'de' => 'Lohnbuchung', 'fr' => 'Écriture des salaires', 'en' => 'Payroll allocation'],
                'descriptions' => ['it' => 'Registra il costo del personale a fronte del debito verso dipendenti.', 'de' => 'Verbucht den Personalaufwand gegen die Verbindlichkeit gegenüber Mitarbeitenden.', 'fr' => 'Enregistre la charge de personnel contre la dette envers les employés.', 'en' => 'Books the salary expense against the amount owed to employees.'],
                'parameters' => $this->parameters(['salary_expense', 'payroll_payable']),
                'lines' => [$this->line('$salary_expense', 'debit', 'amount'), $this->line('$payroll_payable', 'credit', 'amount')],
            ],
            [
                'code' => 'INTEREST_INCOME',
                'category' => 'interest',
                'names' => ['it' => 'Rilevazione interessi attivi', 'de' => 'Zinserträge', 'fr' => 'Intérêts créditeurs', 'en' => 'Interest income'],
                'descriptions' => ['it' => 'Interessi attivi maturati sul conto bancario o su un credito.', 'de' => 'Aufgelaufene Zinserträge auf Bankkonto oder Forderung.', 'fr' => 'Intérêts créditeurs courus sur compte bancaire ou créance.', 'en' => 'Interest earned on a bank account or a receivable.'],
                'parameters' => $this->parameters(['bank_account', 'interest_income']),
                'lines' => [$this->line('$bank_account', 'debit', 'amount'), $this->line('$interest_income', 'credit', 'amount')],
            ],
            [
                'code' => 'INTEREST_EXPENSE',
                'category' => 'interest',
                'names' => ['it' => 'Rilevazione interessi passivi', 'de' => 'Zinsaufwände', 'fr' => 'Intérêts débiteurs', 'en' => 'Interest expense'],
                'descriptions' => ['it' => 'Interessi passivi addebitati dalla banca o dal finanziatore.', 'de' => 'Von Bank oder Kreditgeber belastete Zinsaufwände.', 'fr' => 'Intérêts débiteurs facturés par la banque ou le prêteur.', 'en' => 'Interest charged by the bank or lender.'],
                'parameters' => $this->parameters(['interest_expense', 'bank_account']),
                'lines' => [$this->line('$interest_expense', 'debit', 'amount'), $this->line('$bank_account', 'credit', 'amount')],
            ],
            [
                'code' => 'SELF_INVOICE',
                'category' => 'self_invoice',
                'names' => ['it' => 'Autofattura', 'de' => 'Eigenbeleg', 'fr' => 'Auto-facturation', 'en' => 'Self-invoice'],
                'descriptions' => ['it' => 'Integrazione o autofattura con IVA a credito e a debito (reverse charge).', 'de' => 'Eigenbeleg mit Vorsteuer und Umsatzsteuer (Reverse Charge).', 'fr' => 'Auto-facturation avec TVA déductible et due (autoliquidation).', 'en' => 'Self-invoice with input and output VAT (reverse charge).'],
                'parameters' => $this->parameters(['expense', 'input_vat', 'output_vat', 'counterparty'], true),
                'lines' => [
                    $this->line('$expense', 'debit', 'amount'),
                    $this->line('$input_vat', 'debit', 'tax'),
                    $this->line('$counterparty', 'credit', 'amount'),
                    $this->line('$output_vat', 'credit', 'tax'),
                ],
            ],
            [
                'code' => 'ADVANCE_REVERSAL',
                'category' => 'advance',
                'names' => ['it' => 'Storno anticipi', 'de' => 'Anzahlungsbereinigung', 'fr' => 'Extourne des acomptes', 'en' => 'Advance payment reversal'],
                'descriptions' => ['it' => 'Storna un anticipo ricevuto a fronte del credito verso il cliente.', 'de' => 'Bereinigt eine erhaltene Anzahlung gegen die Kundenforderung.', 'fr' => 'Extourne un acompte reçu contre la créance client.', 'en' => 'Clears a received advance against the customer receivable.'],
                'parameters' => $this->parameters(['advances_received', 'receivable']),
                'lines' => [$this->line('$advances_received', 'debit', 'amount'), $this->line('$receivable', 'credit', 'amount')],
            ],
            [
                'code' => 'EXPENSE_WITH_VAT',
                'category' => 'expense',
                'names' => ['it' => 'Costo con IVA', 'de' => 'Aufwand mit MWST', 'fr' => 'Charge avec TVA', 'en' => 'Expense with VAT'],
                'descriptions' => ['it' => 'Costo imponibile, IVA a credito e debito verso fornitore.', 'de' => 'Nettoaufwand, Vorsteuer und Verbindlichkeit.', 'fr' => 'Charge nette, TVA déductible et dette fournisseur.', 'en' => 'Net expense, input VAT and the supplier payable.'],
                'parameters' => $this->parameters(['expense', 'input_vat', 'payable'], true),
                'lines' => [$this->line('$expense', 'debit', 'amount'), $this->line('$input_vat', 'debit', 'tax'), $this->line('$payable', 'credit', 'gross')],
            ],
            [
                'code' => 'REVENUE_WITH_VAT',
                'category' => 'revenue',
                'names' => ['it' => 'Ricavo con IVA', 'de' => 'Ertrag mit MWST', 'fr' => 'Produit avec TVA', 'en' => 'Revenue with VAT'],
                'descriptions' => ['it' => 'Credito verso cliente, ricavo imponibile e IVA a debito.', 'de' => 'Kundenforderung, Nettoertrag und Umsatzsteuer.', 'fr' => 'Créance client, produit net et TVA due.', 'en' => 'Customer receivable, net revenue and output VAT.'],
                'parameters' => $this->parameters(['receivable', 'revenue', 'output_vat'], true),
                'lines' => [$this->line('$receivable', 'debit', 'gross'), $this->line('$revenue', 'credit', 'amount'), $this->line('$output_vat', 'credit', 'tax')],
            ],
            [
                'code' => 'TAX_PROVISION',
                'category' => 'tax',
                'ledger_type' => 'tax',
                'names' => ['it' => 'Accantonamento imposte (libro fiscale)', 'de' => 'Steuerrückstellung (Steuerbuch)', 'fr' => 'Provision fiscale (livre fiscal)', 'en' => 'Tax provision (tax book)'],
                'descriptions' => ['it' => 'Accantonamento per imposte nel libro fiscale (02).', 'de' => 'Steuerrückstellung im Steuerbuch (02).', 'fr' => 'Provision pour impôts dans le livre fiscal (02).', 'en' => 'Provision for taxes in the tax book (02).'],
                'parameters' => $this->parameters(['tax_expense', 'tax_provision']),
                'lines' => [$this->line('$tax_expense', 'debit', 'amount'), $this->line('$tax_provision', 'credit', 'amount')],
            ],
        ];
    }

    /**
     * @param  list<string>  $accountKeys
     * @return list<array<string, string>>
     */
    private function parameters(array $accountKeys, bool $withRate = false): array
    {
        $parameters = [['key' => 'amount', 'type' => 'amount']];

        if ($withRate) {
            $parameters[] = ['key' => 'tax_rate', 'type' => 'rate'];
        }

        foreach ($accountKeys as $key) {
            $parameters[] = ['key' => $key, 'type' => 'account'];
        }

        return $parameters;
    }

    /**
     * @return array<string, string>
     */
    private function line(string $account, string $side, string $amount): array
    {
        return ['account' => $account, 'side' => $side, 'amount' => $amount];
    }
}
