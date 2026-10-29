<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

class PermissionSeeder extends Seeder
{
    /**
     * Seed standardized enterprise permissions into the global lookup catalog.
     */
    public function run(): void
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
