<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Schema::hasTable('tenants') ? DB::table('tenants')->value('id') : null;
        $tenantId = $tenantId ?? '0192a3b4-c5d6-7e8f-9a0b-1c2d3e4f5a6b';

        $accounts = [
            // Assets (1000 - 1999)
            [
                'code' => '1000',
                'name' => 'Cash on Hand (Cassa contanti / Kasse / Caisse)',
                'class' => 'asset',
                'type' => 'asset',
                'balance' => 'debit',
                'rec_type' => 'bank',
                'is_rec' => true,
            ],
            [
                'code' => '1010',
                'name' => 'Main Operating Bank Account (Banca c/c / Bankkonto / Compte bancaire)',
                'class' => 'asset',
                'type' => 'asset',
                'balance' => 'debit',
                'rec_type' => 'bank',
                'is_rec' => true,
            ],
            [
                'code' => '1100',
                'name' => 'Accounts Receivable (Crediti v/Clienti / Forderungen L+L / Créances clients)',
                'class' => 'asset',
                'type' => 'asset',
                'balance' => 'debit',
                'rec_type' => 'customer',
                'is_rec' => true,
            ],
            [
                'code' => '1150',
                'name' => 'Unbilled Contract Asset (Lavori da fatturare / Nicht abgerechnete Leistungen / Produits à recevoir)',
                'class' => 'asset',
                'type' => 'asset',
                'balance' => 'debit',
                'rec_type' => 'project',
                'is_rec' => false,
            ],
            [
                'code' => '1200',
                'name' => 'Merchandise & Materials Inventory (Rimanenze merci / Warenvorrat / Stocks marchandises)',
                'class' => 'asset',
                'type' => 'asset',
                'balance' => 'debit',
                'rec_type' => 'inventory',
                'is_rec' => true,
            ],
            [
                'code' => '1250',
                'name' => 'Work in Progress WIP Asset (Rimanenze lavori in corso / Unfertige Erzeugnisse / En-cours de production)',
                'class' => 'asset',
                'type' => 'wip',
                'balance' => 'debit',
                'rec_type' => 'wip',
                'is_rec' => true,
            ],
            [
                'code' => '1400',
                'name' => 'Purchase Input VAT (IVA c/Acquisti / Vorsteuer MWST / TVA préalable)',
                'class' => 'asset',
                'type' => 'asset',
                'balance' => 'debit',
                'rec_type' => 'tax',
                'is_rec' => false,
            ],

            // Liabilities (2000 - 2999)
            [
                'code' => '2000',
                'name' => 'Accounts Payable (Debiti v/Fornitori / Verbindlichkeiten L+L / Dettes fournisseurs)',
                'class' => 'liability',
                'type' => 'liability',
                'balance' => 'credit',
                'rec_type' => 'supplier',
                'is_rec' => true,
            ],
            [
                'code' => '2010',
                'name' => 'GR/IR Clearing Account (Fatture da ricevere / Wareneingangsverrechnung / Factures à recevoir)',
                'class' => 'liability',
                'type' => 'liability',
                'balance' => 'credit',
                'rec_type' => 'gr_ir',
                'is_rec' => true,
            ],
            [
                'code' => '2200',
                'name' => 'Sales Output VAT (IVA c/Vendite / Geschuldete MWST / TVA due)',
                'class' => 'liability',
                'type' => 'liability',
                'balance' => 'credit',
                'rec_type' => 'tax',
                'is_rec' => false,
            ],
            [
                'code' => '2210',
                'name' => 'Tax Authority VAT Settlement (Erario c/IVA / MWST Abrechnungskonto / Compte TVA décompte)',
                'class' => 'liability',
                'type' => 'liability',
                'balance' => 'credit',
                'rec_type' => 'tax',
                'is_rec' => false,
            ],
            [
                'code' => '2300',
                'name' => 'Accrued Liabilities & Provisions (Ratei e risconti passivi / Passive Rechnungsabgrenzung / Charges à payer)',
                'class' => 'liability',
                'type' => 'liability',
                'balance' => 'credit',
                'rec_type' => null,
                'is_rec' => false,
            ],

            // Equity (3000 - 3999)
            [
                'code' => '3000',
                'name' => 'Retained Earnings (Utili portati a nuovo / Gewinnvortrag / Report à nouveau)',
                'class' => 'equity',
                'type' => 'equity',
                'balance' => 'credit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '3100',
                'name' => 'Inventory Revaluation Reserve (Riserva rivalutazione / Neubewertungsreserve / Réserve de réévaluation)',
                'class' => 'equity',
                'type' => 'equity',
                'balance' => 'credit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '3990',
                'name' => 'Reserve for Encumbrances (Riserva impegni / Verpflichtungsreserve / Réserve d engagements)',
                'class' => 'equity',
                'type' => 'equity',
                'balance' => 'credit',
                'rec_type' => null,
                'is_rec' => false,
            ],

            // Revenue (4000 - 4999)
            [
                'code' => '4000',
                'name' => 'Contract & Sales Revenue (Ricavi delle vendite / Erlöse aus Lieferungen / Chiffre d affaires)',
                'class' => 'revenue',
                'type' => 'revenue',
                'balance' => 'credit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '4100',
                'name' => 'Service & Consulting Revenue (Ricavi prestazioni servizi / Dienstleistungserlöse / Prestations de services)',
                'class' => 'revenue',
                'type' => 'revenue',
                'balance' => 'credit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '4900',
                'name' => 'Statistical Margin Ledger (Margine statistico commessa / Statistische Marge / Marge statistique)',
                'class' => 'statistical',
                'type' => 'statistical',
                'balance' => 'debit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '4990',
                'name' => 'Statistical Offset Clearing (Contropartita statistica / Statistischer Ausgleich / Contrepartie statistique)',
                'class' => 'statistical',
                'type' => 'statistical',
                'balance' => 'credit',
                'rec_type' => null,
                'is_rec' => false,
            ],

            // COGS (5000 - 5999)
            [
                'code' => '5000',
                'name' => 'Cost of Goods Sold (Costo del venduto COGS / Materialaufwand / Coût des ventes)',
                'class' => 'cogs',
                'type' => 'cogs',
                'balance' => 'debit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '5100',
                'name' => 'Inventory Variance & Shrinkage (Variazioni rimanenze / Bestandesänderungen / Variation des stocks)',
                'class' => 'expense',
                'type' => 'expense',
                'balance' => 'debit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '5900',
                'name' => 'Capitalized WIP Cost Offset (Incrementi immobilizzazioni / Eigenleistungen / Production immobilisée)',
                'class' => 'expense',
                'type' => 'expense',
                'balance' => 'credit',
                'rec_type' => null,
                'is_rec' => false,
            ],

            // Expenses (6000 - 6999)
            [
                'code' => '6000',
                'name' => 'Direct Labor & Wages (Salari e stipendi / Löhne und Gehälter / Salaires et traitements)',
                'class' => 'expense',
                'type' => 'expense',
                'balance' => 'debit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '6100',
                'name' => 'Subcontractor & Outsourcing Services (Lavorazioni terzi / Fremdleistungen / Sous-traitance)',
                'class' => 'expense',
                'type' => 'expense',
                'balance' => 'debit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '6200',
                'name' => 'Travel & Project Expenses (Spese trasferta / Reisekosten / Frais de déplacement)',
                'class' => 'expense',
                'type' => 'expense',
                'balance' => 'debit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '6500',
                'name' => 'Inventory Scrap & Loss (Perdite su merci e rottamazioni / Ausfallverluste / Pertes sur stocks)',
                'class' => 'expense',
                'type' => 'expense',
                'balance' => 'debit',
                'rec_type' => null,
                'is_rec' => false,
            ],
            [
                'code' => '6990',
                'name' => 'Encumbrance Expense (Impegni di spesa / Verpflichtungsaufwand / Dépenses engagées)',
                'class' => 'expense',
                'type' => 'expense',
                'balance' => 'debit',
                'rec_type' => null,
                'is_rec' => false,
            ],
        ];

        foreach ($accounts as $acc) {
            $legacyType = in_array($acc['class'], ['asset', 'liability', 'equity', 'revenue', 'expense'], true)
                ? $acc['class']
                : match ($acc['type']) {
                    'wip' => 'asset',
                    'cogs' => 'expense',
                    'statistical', 'memo' => 'equity',
                    default => 'asset',
                };

            $existing = DB::table('chart_of_accounts')
                ->where('tenant_id', $tenantId)
                ->where(function ($q) use ($acc) {
                    $q->where('account_code', $acc['code'])
                        ->orWhere('code', $acc['code']);
                })
                ->first();

            if (! $existing) {
                DB::table('chart_of_accounts')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'company_id' => $tenantId,
                    'code' => $acc['code'],
                    'account_code' => $acc['code'],
                    'name' => $acc['name'],
                    'account_class' => $acc['class'],
                    'account_type' => $acc['type'],
                    'type' => $legacyType,
                    'normal_balance' => $acc['balance'],
                    'currency' => 'EUR',
                    'reconciliation_type' => $acc['rec_type'],
                    'is_reconciliation_account' => $acc['is_rec'],
                    'allows_manual_posting' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('chart_of_accounts')->where('id', $existing->id)->update([
                    'code' => $acc['code'],
                    'account_code' => $acc['code'],
                    'name' => $acc['name'],
                    'account_class' => $acc['class'],
                    'account_type' => $acc['type'],
                    'type' => $legacyType,
                    'normal_balance' => $acc['balance'],
                    'reconciliation_type' => $acc['rec_type'],
                    'is_reconciliation_account' => $acc['is_rec'],
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
