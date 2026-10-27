<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ApprovalStatus;
use App\Models\ApprovalStatusTranslation;
use App\Models\WorkflowState;
use App\Models\WorkflowStateTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WorkflowApprovalStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $workflowStates = [
            [
                'scope' => 'approval_chain',
                'code' => 'DRAFT',
                'is_initial' => true,
                'is_final' => false,
                'translations' => [
                    'it' => ['name' => 'Bozza Iniziale', 'description' => 'Documento o transazione in fase di compilazione'],
                    'en' => ['name' => 'Draft', 'description' => 'Document being drafted, not yet entered into workflow'],
                    'de' => ['name' => 'Entwurf', 'description' => 'Dokument im Entwurfsstadium, noch nicht im Genehmigungslauf'],
                    'fr' => ['name' => 'Brouillon', 'description' => 'Document en cours de saisie, pas encore soumis'],
                ],
            ],
            [
                'scope' => 'approval_chain',
                'code' => 'PENDING_APPROVAL',
                'is_initial' => false,
                'is_final' => false,
                'translations' => [
                    'it' => ['name' => 'In Attesa di Approvazione', 'description' => 'In attesa di firma/approvazione da parte del responsabile'],
                    'en' => ['name' => 'Pending Approval', 'description' => 'Submitted and awaiting supervisor review and sign-off'],
                    'de' => ['name' => 'Genehmigung ausstehend', 'description' => 'Eingereicht und wartet auf Prüfung durch Vorgesetzte'],
                    'fr' => ['name' => 'En attente d\'approbation', 'description' => 'Soumis et en attente de visa du responsable hiérarchique'],
                ],
            ],
            [
                'scope' => 'approval_chain',
                'code' => 'APPROVED',
                'is_initial' => false,
                'is_final' => true,
                'translations' => [
                    'it' => ['name' => 'Approvato', 'description' => 'Validato da tutti i livelli gerarchici richiesti'],
                    'en' => ['name' => 'Approved', 'description' => 'Formally signed off by all required authorization tiers'],
                    'de' => ['name' => 'Genehmigt', 'description' => 'Von allen erforderlichen Freigabestufen bestätigt'],
                    'fr' => ['name' => 'Approuvé', 'description' => 'Validé par tous les échelons hiérarchiques requis'],
                ],
            ],
            [
                'scope' => 'approval_chain',
                'code' => 'REJECTED',
                'is_initial' => false,
                'is_final' => true,
                'translations' => [
                    'it' => ['name' => 'Respinto / Rifiutato', 'description' => 'Rifiutato dall\'approvatore con motivazione registrata'],
                    'en' => ['name' => 'Rejected', 'description' => 'Declined by reviewer with mandatory recorded rationale'],
                    'de' => ['name' => 'Abgelehnt', 'description' => 'Vom Genehmiger mit Begründung abgelehnt'],
                    'fr' => ['name' => 'Rejeté', 'description' => 'Refusé par le valideur avec motif obligatoire consigné'],
                ],
            ],
        ];

        $approvalStatuses = [
            [
                'code' => 'PENDING',
                'color_badge' => '#F59E0B',
                'translations' => [
                    'it' => ['name' => 'In Attesa', 'description' => 'Richiesta di approvazione pendente'],
                    'en' => ['name' => 'Pending', 'description' => 'Approval request awaiting action'],
                    'de' => ['name' => 'Ausstehend', 'description' => 'Genehmigungsanforderung wartet auf Bearbeitung'],
                    'fr' => ['name' => 'En attente', 'description' => 'Demande d\'approbation en attente d\'action'],
                ],
            ],
            [
                'code' => 'AUTHORIZED',
                'color_badge' => '#10B981',
                'translations' => [
                    'it' => ['name' => 'Autorizzato', 'description' => 'Autorizzazione concessa'],
                    'en' => ['name' => 'Authorized', 'description' => 'Authorization formally granted'],
                    'de' => ['name' => 'Autorisiert', 'description' => 'Freigabe erteilt'],
                    'fr' => ['name' => 'Autorisé', 'description' => 'Autorisation formellement accordée'],
                ],
            ],
            [
                'code' => 'DECLINED',
                'color_badge' => '#EF4444',
                'translations' => [
                    'it' => ['name' => 'Rifiutato', 'description' => 'Autorizzazione negata'],
                    'en' => ['name' => 'Declined', 'description' => 'Authorization declined'],
                    'de' => ['name' => 'Abgelehnt', 'description' => 'Freigabe verweigert'],
                    'fr' => ['name' => 'Refusé', 'description' => 'Autorisation refusée'],
                ],
            ],
        ];

        DB::transaction(function () use ($workflowStates, $approvalStatuses): void {
            foreach ($workflowStates as $stateData) {
                /** @var WorkflowState $wState */
                $wState = WorkflowState::updateOrCreate(
                    [
                        'scope' => $stateData['scope'],
                        'code' => $stateData['code'],
                    ],
                    [
                        'is_initial' => $stateData['is_initial'],
                        'is_final' => $stateData['is_final'],
                    ]
                );

                foreach ($stateData['translations'] as $locale => $trans) {
                    WorkflowStateTranslation::updateOrCreate(
                        [
                            'workflow_state_id' => $wState->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $trans['name'],
                            'description' => $trans['description'],
                        ]
                    );
                }
            }

            foreach ($approvalStatuses as $apprData) {
                /** @var ApprovalStatus $appr */
                $appr = ApprovalStatus::updateOrCreate(
                    ['code' => $apprData['code']],
                    ['color_badge' => $apprData['color_badge']]
                );

                foreach ($apprData['translations'] as $locale => $trans) {
                    ApprovalStatusTranslation::updateOrCreate(
                        [
                            'approval_status_id' => $appr->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $trans['name'],
                            'description' => $trans['description'],
                        ]
                    );
                }
            }
        });
    }
}
