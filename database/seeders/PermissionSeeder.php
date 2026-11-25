<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;

class PermissionSeeder extends GlobalDataSeeder
{
    /**
     * Seed standardized enterprise permissions into the global lookup catalog.
     */
    protected function seedGlobalData(): void
    {
        $category = LookupCategory::updateOrCreate(
            ['code' => 'enterprise_permission'],
            [
                'name' => 'Permessi di Sistema / Enterprise RBAC Permissions',
                'description' => 'Granular permissions catalog across all ERP modules',
                'is_system' => true,
            ]
        );

        $permissions = [
            ['code' => 'crm.view', 'label_it' => 'Visualizzazione CRM', 'label_en' => 'View CRM Records'],
            ['code' => 'crm.manage', 'label_it' => 'Gestione Completa CRM', 'label_en' => 'Full CRM Management'],
            ['code' => 'sales.orders.create', 'label_it' => 'Creazione Ordini Vendita', 'label_en' => 'Create Sales Orders'],
            ['code' => 'sales_orders.approve_credit', 'label_it' => 'Sblocco Ordini Bloccati per Fido', 'label_en' => 'Release Orders Blocked by Credit'],
            ['code' => 'invoicing.issue', 'label_it' => 'Emissione Fatture Attive', 'label_en' => 'Issue Sales Invoices'],
            ['code' => 'procurement.po.approve', 'label_it' => 'Approvazione Ordini Acquisto', 'label_en' => 'Approve Purchase Orders'],
            ['code' => 'inventory.movements.post', 'label_it' => 'Registrazione Movimenti Magazzino', 'label_en' => 'Post Stock Movements'],
            ['code' => 'manufacturing.mrp.execute', 'label_it' => 'Esecuzione Calcolo MRP', 'label_en' => 'Execute MRP Calculation'],
            ['code' => 'manufacturing.orders.release', 'label_it' => 'Rilascio Ordini Produzione', 'label_en' => 'Release Production Orders'],
            ['code' => 'gl.journals.post', 'label_it' => 'Registrazione Scritture Contabili', 'label_en' => 'Post General Ledger Journals'],
            ['code' => 'treasury.payments.execute', 'label_it' => 'Esecuzione Pagamenti Bancari', 'label_en' => 'Execute Banking Dispositions'],
            ['code' => 'service.tickets.dispatch', 'label_it' => 'Assegnazione Ticket Assistenza', 'label_en' => 'Dispatch Service Tickets'],
            ['code' => 'assets.depreciation.run', 'label_it' => 'Esecuzione Ammortamenti Cespiti', 'label_en' => 'Run Asset Depreciation'],
            ['code' => 'customs.view', 'label_it' => 'Visualizzazione Dogane e Origine', 'label_en' => 'View Customs and Origin Data'],
            ['code' => 'customs.manage', 'label_it' => 'Gestione Dogane e Origine', 'label_en' => 'Manage Customs and Origin Data'],
            ['code' => 'delivery_notes.view', 'label_it' => 'Visualizzazione DDT', 'label_en' => 'View Delivery Notes'],
            ['code' => 'delivery_notes.create', 'label_it' => 'Creazione DDT', 'label_en' => 'Create Delivery Notes'],
            ['code' => 'delivery_notes.update', 'label_it' => 'Modifica DDT in bozza', 'label_en' => 'Edit Draft Delivery Notes'],
            ['code' => 'delivery_notes.delete', 'label_it' => 'Eliminazione DDT in bozza', 'label_en' => 'Delete Draft Delivery Notes'],
            ['code' => 'delivery_notes.issue', 'label_it' => 'Emissione, spedizione e consegna DDT', 'label_en' => 'Issue, Dispatch and Deliver Delivery Notes'],
            ['code' => 'delivery_notes.cancel', 'label_it' => 'Annullamento DDT', 'label_en' => 'Cancel Delivery Notes'],
            ['code' => 'delivery_notes.invoice', 'label_it' => 'Fatturazione differita DDT', 'label_en' => 'Deferred Invoicing of Delivery Notes'],
            ['code' => 'delivery_notes.pdf', 'label_it' => 'Download PDF DDT', 'label_en' => 'Download Delivery Note PDF'],
            ['code' => 'logistics.transfers.manage', 'label_it' => 'Gestione Trasferimenti di Magazzino', 'label_en' => 'Manage Stock Transfers'],
            ['code' => 'logistics.transfers.approve', 'label_it' => 'Approvazione Trasferimenti e Discrepanze di Magazzino', 'label_en' => 'Approve Stock Transfers & Discrepancies'],
            ['code' => 'logistics.consignment.reconcile', 'label_it' => 'Riconciliazione Conto Deposito e Conto Lavoro', 'label_en' => 'Reconcile Consignment & Subcontracting'],
            ['code' => 'companies.view', 'label_it' => 'Consultazione Società del Gruppo', 'label_en' => 'View Group Companies'],
            ['code' => 'companies.manage', 'label_it' => 'Gestione Società, Sedi e Conti Intercompany', 'label_en' => 'Manage Companies, Sites and Intercompany Accounts'],
            ['code' => 'companies.members.manage', 'label_it' => 'Gestione Accessi per Società', 'label_en' => 'Manage Per-Company Access'],
            ['code' => 'companies.intercompany.view', 'label_it' => 'Consultazione Operazioni Intercompany', 'label_en' => 'View Intercompany Transactions'],
            ['code' => 'companies.intercompany.manage', 'label_it' => 'Registrazione e Storno Operazioni Intercompany', 'label_en' => 'Record and Cancel Intercompany Transactions'],
            ['code' => 'companies.consolidation.view', 'label_it' => 'Consultazione Bilancio Consolidato', 'label_en' => 'View Consolidated Reports'],
            ['code' => 'companies.consolidation.run', 'label_it' => 'Esecuzione Consolidamento di Gruppo', 'label_en' => 'Run Group Consolidation'],
            ['code' => 'audit.ledger.view', 'label_it' => 'Consultazione Piste di Audit', 'label_en' => 'Inspect Enterprise Audit Trails'],
            ['code' => 'audit.view', 'label_it' => 'Consultazione Audit Trail Immutabile', 'label_en' => 'View the Immutable Audit Trail'],
            ['code' => 'audit.verify', 'label_it' => 'Verifica Integrità Crittografica Audit Trail', 'label_en' => 'Verify Audit Trail Cryptographic Integrity'],
            ['code' => 'audit.export', 'label_it' => 'Esportazione Bundle Forense Audit Trail', 'label_en' => 'Export the Forensic Audit Bundle'],
            ['code' => 'privacy.pii.unmask', 'label_it' => 'Visualizzazione Dati Personali in Chiaro', 'label_en' => 'Reveal Unmasked Personal Data'],
            ['code' => 'audit.worm.manage', 'label_it' => 'Gestione Archivio WORM Audit Trail', 'label_en' => 'Manage the Audit WORM Archive'],
            ['code' => 'hr.payroll.view', 'label_it' => 'Consultazione Paghe Svizzere', 'label_en' => 'View Swiss Payroll'],
            ['code' => 'hr.payroll.manage', 'label_it' => 'Gestione Paghe Svizzere', 'label_en' => 'Manage Swiss Payroll'],
            ['code' => 'van_sales.view', 'label_it' => 'Consultazione Tentata Vendita e Field Sales', 'label_en' => 'View Van Selling & Field Sales'],
            ['code' => 'van_sales.manage', 'label_it' => 'Gestione Furgoni, Carichi e Giri Tentata Vendita', 'label_en' => 'Manage Van Selling Warehouses, Loads & Routes'],
            ['code' => 'van_sales.settle', 'label_it' => 'Chiusura e Quadratura Fine Giro Furgone', 'label_en' => 'Complete End-of-Day Van Settlement'],
            ['code' => 'van_sales.credit_override', 'label_it' => 'Sblocco Fido e Incasso in Tentata Vendita', 'label_en' => 'Override/Unblock Customer Credit in Field Sales'],
            ['code' => 'sales_bc.view', 'label_it' => 'Consultazione CRM & Vendite Omnicanale (SD#139)', 'label_en' => 'View Omnichannel CRM & Sales Pipeline (SD#139)'],
            ['code' => 'sales_bc.manage', 'label_it' => 'Gestione Pipeline CRM, CPQ, Ordini, DDT & Provvigioni (SD#139)', 'label_en' => 'Manage CRM, CPQ, Orders, DDT & Commissions (SD#139)'],
            ['code' => 'sales_bc.approve_margin', 'label_it' => 'Approvazione Deroga Margine Minimo (Mod 038)', 'label_en' => 'Approve Minimum Margin Guardrail Override (Mod 038)'],
        ];

        foreach ($permissions as $idx => $perm) {
            $item = LookupItem::updateOrCreate(
                [
                    'tenant_id' => null,
                    'category_id' => $category->id,
                    'code' => $perm['code'],
                ],
                [
                    'sort_order' => $idx + 1,
                    'is_active' => true,
                    'is_system' => true,
                ]
            );

            LookupItemTranslation::updateOrCreate(
                [
                    'tenant_id' => null,
                    'lookup_item_id' => $item->id,
                    'locale' => 'it',
                ],
                [
                    'label' => $perm['label_it'],
                    'short_label' => $perm['code'],
                    'description' => "Permesso per {$perm['label_it']}",
                ]
            );

            LookupItemTranslation::updateOrCreate(
                [
                    'tenant_id' => null,
                    'lookup_item_id' => $item->id,
                    'locale' => 'en',
                ],
                [
                    'label' => $perm['label_en'],
                    'short_label' => $perm['code'],
                    'description' => "Permission granting {$perm['label_en']}",
                ]
            );
        }
    }
}
