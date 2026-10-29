<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ProjectStatus;
use App\Models\ProjectStatusTranslation;
use App\Models\TaskStatus;
use App\Models\TaskStatusTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProjectTaskStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $projectStatuses = [
            [
                'code' => 'PLANNED',
                'color_badge' => '#94A3B8',
                'sort_order' => 10,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Pianificato', 'description' => 'Progetto in fase di pianificazione e definizione budget'],
                    'en' => ['label' => 'Planned', 'description' => 'Project in initial planning and budgeting stage'],
                    'de' => ['label' => 'Geplant', 'description' => 'Projekt in Planungs- und Budgetierungsphase'],
                    'fr' => ['label' => 'Planifié', 'description' => 'Projet en phase de planification et de cadrage'],
                ],
            ],
            [
                'code' => 'IN_PROGRESS',
                'color_badge' => '#3B82F6',
                'sort_order' => 20,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'In Corso', 'description' => 'Progetto attivo con ore e costi in consuntivazione'],
                    'en' => ['label' => 'In Progress', 'description' => 'Project actively underway with time and cost logging'],
                    'de' => ['label' => 'In Ausführung', 'description' => 'Projekt läuft aktiv mit Zeiterfassung und Kostenanfall'],
                    'fr' => ['label' => 'En cours', 'description' => 'Projet actif avec suivi des heures et des coûts'],
                ],
            ],
            [
                'code' => 'ON_HOLD',
                'color_badge' => '#F59E0B',
                'sort_order' => 30,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'In Sospeso', 'description' => 'Attività temporaneamente congelate in attesa di autorizzazioni'],
                    'en' => ['label' => 'On Hold', 'description' => 'Project temporarily paused pending client approval or inputs'],
                    'de' => ['label' => 'Pausiert', 'description' => 'Projekt vorübergehend angehalten wegen ausstehender Freigaben'],
                    'fr' => ['label' => 'En pause', 'description' => 'Projet temporairement suspendu en attente de validation'],
                ],
            ],
            [
                'code' => 'COMPLETED',
                'color_badge' => '#10B981',
                'sort_order' => 40,
                'is_terminal' => true,
                'translations' => [
                    'it' => ['label' => 'Completato', 'description' => 'Deliverable consegnati e progetto collaudato con successo'],
                    'en' => ['label' => 'Completed', 'description' => 'Milestones delivered and formally accepted by customer'],
                    'de' => ['label' => 'Abgeschlossen', 'description' => 'Projektziele erreicht und vom Kunden abgenommen'],
                    'fr' => ['label' => 'Terminé', 'description' => 'Livrables remis et projet réceptionné avec succès'],
                ],
            ],
            [
                'code' => 'CANCELLED',
                'color_badge' => '#EF4444',
                'sort_order' => 99,
                'is_terminal' => true,
                'translations' => [
                    'it' => ['label' => 'Annullato', 'description' => 'Progetto interrotto prima del termine dei lavori'],
                    'en' => ['label' => 'Cancelled', 'description' => 'Project discontinued prior to completion'],
                    'de' => ['label' => 'Abgebrochen', 'description' => 'Projekt vorzeitig beendet'],
                    'fr' => ['label' => 'Annulé', 'description' => 'Projet interrompu avant son achèvement'],
                ],
            ],
        ];

        $taskStatuses = [
            [
                'code' => 'TODO',
                'color_badge' => '#94A3B8',
                'sort_order' => 10,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Da Fare', 'description' => 'Attività assegnata non ancora iniziata'],
                    'en' => ['label' => 'To Do', 'description' => 'Task queued and not yet started'],
                    'de' => ['label' => 'Zu erledigen', 'description' => 'Aufgabe zugewiesen, noch nicht begonnen'],
                    'fr' => ['label' => 'À faire', 'description' => 'Tâche planifiée non encore démarrée'],
                ],
            ],
            [
                'code' => 'IN_PROGRESS',
                'color_badge' => '#3B82F6',
                'sort_order' => 20,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'In Lavorazione', 'description' => 'Attività attivamente in esecuzione'],
                    'en' => ['label' => 'In Progress', 'description' => 'Task currently in execution'],
                    'de' => ['label' => 'In Bearbeitung', 'description' => 'Aufgabe wird derzeit bearbeitet'],
                    'fr' => ['label' => 'En cours', 'description' => 'Tâche actuellement en cours d\'exécution'],
                ],
            ],
            [
                'code' => 'IN_REVIEW',
                'color_badge' => '#8B5CF6',
                'sort_order' => 30,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'In Revisione / QA', 'description' => 'Attività terminata in attesa di verifica qualitativa'],
                    'en' => ['label' => 'Under Review / QA', 'description' => 'Task completed, awaiting peer review or QA approval'],
                    'de' => ['label' => 'In Überprüfung (QA)', 'description' => 'Aufgabe erledigt, Qualitätsprüfung ausstehend'],
                    'fr' => ['label' => 'En révision (QA)', 'description' => 'Tâche finie, en attente de contrôle qualité'],
                ],
            ],
            [
                'code' => 'DONE',
                'color_badge' => '#10B981',
                'sort_order' => 40,
                'is_terminal' => true,
                'translations' => [
                    'it' => ['label' => 'Completata', 'description' => 'Attività approvata e chiusa'],
                    'en' => ['label' => 'Done', 'description' => 'Task verified and marked as done'],
                    'de' => ['label' => 'Erledigt', 'description' => 'Aufgabe erfolgreich abgeschlossen'],
                    'fr' => ['label' => 'Terminée', 'description' => 'Tâche validée et clôturée'],
                ],
            ],
        ];

        DB::transaction(function () use ($projectStatuses, $taskStatuses): void {
            foreach ($projectStatuses as $statusData) {
                /** @var ProjectStatus $pStatus */
                $pStatus = ProjectStatus::updateOrCreate(
                    ['code' => $statusData['code']],
                    [
                        'color_badge' => $statusData['color_badge'],
                        'sort_order' => $statusData['sort_order'],
                        'is_terminal' => $statusData['is_terminal'],
                    ]
                );

                foreach ($statusData['translations'] as $locale => $trans) {
                    ProjectStatusTranslation::updateOrCreate(
                        [
                            'project_status_id' => $pStatus->id,
                            'locale' => $locale,
                        ],
                        [
                            'label' => $trans['label'],
                            'description' => $trans['description'],
                        ]
                    );
                }
            }

            foreach ($taskStatuses as $tData) {
                /** @var TaskStatus $tStatus */
                $tStatus = TaskStatus::updateOrCreate(
                    ['code' => $tData['code']],
                    [
                        'color_badge' => $tData['color_badge'],
                        'sort_order' => $tData['sort_order'],
                        'is_terminal' => $tData['is_terminal'],
                    ]
                );

                foreach ($tData['translations'] as $locale => $trans) {
                    TaskStatusTranslation::updateOrCreate(
                        [
                            'task_status_id' => $tStatus->id,
                            'locale' => $locale,
                        ],
                        [
                            'label' => $trans['label'],
                            'description' => $trans['description'],
                        ]
                    );
                }
            }
        });
    }
}
