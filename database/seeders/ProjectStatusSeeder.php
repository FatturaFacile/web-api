<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class ProjectStatusSeeder extends Seeder
{
    public function run(): void
    {
        $definitions = [
            [
                'code' => 'project_status',
                'name' => 'Project Lifecycle Statuses',
                'description' => 'Lifecycle statuses for projects from inception to formal closure',
                'items' => [
                    ['code' => 'draft', 'sort' => 10, 'translations' => ['en' => 'Draft', 'it' => 'Bozza', 'de' => 'Entwurf', 'fr' => 'Brouillon']],
                    ['code' => 'planned', 'sort' => 20, 'translations' => ['en' => 'Planned', 'it' => 'Pianificato', 'de' => 'Geplant', 'fr' => 'Planifié']],
                    ['code' => 'approved', 'sort' => 30, 'translations' => ['en' => 'Approved', 'it' => 'Approvato', 'de' => 'Genehmigt', 'fr' => 'Approuvé']],
                    ['code' => 'active', 'sort' => 40, 'translations' => ['en' => 'Active / In Execution', 'it' => 'Attivo / In Esecuzione', 'de' => 'Aktiv / In Ausführung', 'fr' => 'Actif / En cours']],
                    ['code' => 'on_hold', 'sort' => 50, 'translations' => ['en' => 'On Hold / Suspended', 'it' => 'Sospeso', 'de' => 'Angehalten', 'fr' => 'En pause']],
                    ['code' => 'completed', 'sort' => 60, 'translations' => ['en' => 'Completed', 'it' => 'Completato', 'de' => 'Abgeschlossen', 'fr' => 'Terminé']],
                    ['code' => 'closed', 'sort' => 70, 'translations' => ['en' => 'Closed', 'it' => 'Chiuso', 'de' => 'Geschlossen', 'fr' => 'Clôturé']],
                    ['code' => 'cancelled', 'sort' => 80, 'translations' => ['en' => 'Cancelled', 'it' => 'Annullato', 'de' => 'Storniert', 'fr' => 'Annulé']],
                ],
            ],
            [
                'code' => 'project_task_status',
                'name' => 'Task Execution Statuses',
                'description' => 'Work Breakdown Structure (WBS) activity execution lifecycle',
                'items' => [
                    ['code' => 'todo', 'sort' => 10, 'translations' => ['en' => 'To Do', 'it' => 'Da Fare', 'de' => 'Zu erledigen', 'fr' => 'À faire']],
                    ['code' => 'in_progress', 'sort' => 20, 'translations' => ['en' => 'In Progress', 'it' => 'In Corso', 'de' => 'In Bearbeitung', 'fr' => 'En cours']],
                    ['code' => 'review', 'sort' => 30, 'translations' => ['en' => 'Under Review', 'it' => 'In Revisione', 'de' => 'In Prüfung', 'fr' => 'En revue']],
                    ['code' => 'blocked', 'sort' => 40, 'translations' => ['en' => 'Blocked', 'it' => 'Bloccato', 'de' => 'Blockiert', 'fr' => 'Bloqué']],
                    ['code' => 'completed', 'sort' => 50, 'translations' => ['en' => 'Completed', 'it' => 'Completato', 'de' => 'Abgeschlossen', 'fr' => 'Terminé']],
                    ['code' => 'cancelled', 'sort' => 60, 'translations' => ['en' => 'Cancelled', 'it' => 'Annullato', 'de' => 'Storniert', 'fr' => 'Annulé']],
                ],
            ],
            [
                'code' => 'project_budget_status',
                'name' => 'Project Budget Statuses',
                'description' => 'Financial budget revision and approval governance status',
                'items' => [
                    ['code' => 'draft', 'sort' => 10, 'translations' => ['en' => 'Draft', 'it' => 'Bozza', 'de' => 'Entwurf', 'fr' => 'Brouillon']],
                    ['code' => 'pending_approval', 'sort' => 20, 'translations' => ['en' => 'Pending Approval', 'it' => 'In Attesa di Approvazione', 'de' => 'Genehmigung ausstehend', 'fr' => 'En attente d\'approbation']],
                    ['code' => 'approved', 'sort' => 30, 'translations' => ['en' => 'Approved', 'it' => 'Approvato', 'de' => 'Genehmigt', 'fr' => 'Approuvé']],
                    ['code' => 'rejected', 'sort' => 40, 'translations' => ['en' => 'Rejected', 'it' => 'Respinto', 'de' => 'Abgelehnt', 'fr' => 'Rejeté']],
                    ['code' => 'superseded', 'sort' => 50, 'translations' => ['en' => 'Superseded', 'it' => 'Superato', 'de' => 'Ersetzt', 'fr' => 'Remplacé']],
                ],
            ],
            [
                'code' => 'project_change_order_status',
                'name' => 'Change Order Statuses',
                'description' => 'Contractual scope and budget amendment approval lifecycle',
                'items' => [
                    ['code' => 'draft', 'sort' => 10, 'translations' => ['en' => 'Draft', 'it' => 'Bozza', 'de' => 'Entwurf', 'fr' => 'Brouillon']],
                    ['code' => 'pending_approval', 'sort' => 20, 'translations' => ['en' => 'Pending Approval', 'it' => 'In Attesa di Approvazione', 'de' => 'Genehmigung ausstehend', 'fr' => 'En attente d\'approbation']],
                    ['code' => 'internal_approved', 'sort' => 30, 'translations' => ['en' => 'Internally Approved', 'it' => 'Approvato Internamente', 'de' => 'Intern genehmigt', 'fr' => 'Approuvé en interne']],
                    ['code' => 'customer_approved', 'sort' => 40, 'translations' => ['en' => 'Customer Approved', 'it' => 'Approvato dal Cliente', 'de' => 'Vom Kunden genehmigt', 'fr' => 'Approuvé par le client']],
                    ['code' => 'rejected', 'sort' => 50, 'translations' => ['en' => 'Rejected', 'it' => 'Respinto', 'de' => 'Abgelehnt', 'fr' => 'Rejeté']],
                    ['code' => 'implemented', 'sort' => 60, 'translations' => ['en' => 'Implemented', 'it' => 'Implementato', 'de' => 'Implementiert', 'fr' => 'Implémenté']],
                ],
            ],
            [
                'code' => 'project_timesheet_status',
                'name' => 'Timesheet Statuses',
                'description' => 'Labour time tracking validation and payroll/costing posting status',
                'items' => [
                    ['code' => 'draft', 'sort' => 10, 'translations' => ['en' => 'Draft', 'it' => 'Bozza', 'de' => 'Entwurf', 'fr' => 'Brouillon']],
                    ['code' => 'submitted', 'sort' => 20, 'translations' => ['en' => 'Submitted', 'it' => 'Inviato', 'de' => 'Eingereicht', 'fr' => 'Soumis']],
                    ['code' => 'approved', 'sort' => 30, 'translations' => ['en' => 'Approved', 'it' => 'Approvato', 'de' => 'Genehmigt', 'fr' => 'Approuvé']],
                    ['code' => 'rejected', 'sort' => 40, 'translations' => ['en' => 'Rejected', 'it' => 'Respinto', 'de' => 'Abgelehnt', 'fr' => 'Rejeté']],
                    ['code' => 'posted', 'sort' => 50, 'translations' => ['en' => 'Posted to Job Costing', 'it' => 'Registrato a Costi', 'de' => 'Verbucht', 'fr' => 'Comptabilisé']],
                ],
            ],
        ];

        foreach ($definitions as $def) {
            $cat = LookupCategory::updateOrCreate(
                ['code' => $def['code']],
                [
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'is_system' => true,
                ]
            );

            foreach ($def['items'] as $item) {
                $lookupItem = LookupItem::updateOrCreate(
                    [
                        'category_id' => $cat->id,
                        'code' => $item['code'],
                    ],
                    [
                        'sort_order' => $item['sort'],
                        'is_active' => true,
                        'is_default' => $item['code'] === 'draft' || $item['code'] === 'todo',
                    ]
                );

                foreach (['en', 'it', 'de', 'fr'] as $lang) {
                    LookupItemTranslation::updateOrCreate(
                        [
                            'lookup_item_id' => $lookupItem->id,
                            'locale' => $lang,
                        ],
                        [
                            'label' => $item['translations'][$lang],
                            'description' => $item['translations'][$lang],
                        ]
                    );
                }
            }
        }
    }
}
