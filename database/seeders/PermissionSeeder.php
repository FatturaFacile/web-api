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
            ['code' => 'logistics.consignment.reconcile', 'label_it' => 'Riconciliazione Conto Deposito e Conto Lavoro', 'label_en' => 'Reconcile Consignment & Subcontracting'],
            ['code' => 'audit.ledger.view', 'label_it' => 'Consultazione Piste di Audit', 'label_en' => 'Inspect Enterprise Audit Trails'],
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
