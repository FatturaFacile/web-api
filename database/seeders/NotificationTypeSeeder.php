<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\NotificationType;
use App\Models\NotificationTypeTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NotificationTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'INVOICE_OVERDUE',
                'channel' => 'email',
                'translations' => [
                    'it' => ['name' => 'Fattura Scaduta / Sollecito', 'description' => 'Avviso di scadenza pagamento fattura clienti'],
                    'en' => ['name' => 'Invoice Overdue Notice', 'description' => 'Notification for customer overdue invoice reminder'],
                    'de' => ['name' => 'Rechnungsmahnung / Zahlungsverzug', 'description' => 'Mitteilung über überfällige Kundenrechnung'],
                    'fr' => ['name' => 'Rappel de facture impayée', 'description' => 'Avis de relance pour facture client échue'],
                ],
            ],
            [
                'code' => 'STOCK_LOW_THRESHOLD',
                'channel' => 'in_app',
                'translations' => [
                    'it' => ['name' => 'Sotto Scorta Magazzino', 'description' => 'Giacenza articolo inferiore al punto di riordino'],
                    'en' => ['name' => 'Low Stock Warning', 'description' => 'Inventory quantity fell below safety reorder point'],
                    'de' => ['name' => 'Mindestbestand unterschritten', 'description' => 'Lagerbestand unter den Meldebestand gefallen'],
                    'fr' => ['name' => 'Alerte stock minimum', 'description' => 'Quantité en stock inférieure au seuil de réapprovisionnement'],
                ],
            ],
            [
                'code' => 'APPROVAL_REQUESTED',
                'channel' => 'in_app',
                'translations' => [
                    'it' => ['name' => 'Richiesta di Approvazione', 'description' => 'Nuovo documento in attesa della tua autorizzazione'],
                    'en' => ['name' => 'Approval Requested', 'description' => 'New document submitted requiring your sign-off'],
                    'de' => ['name' => 'Genehmigungsanforderung', 'description' => 'Neues Dokument wartet auf Ihre Freigabe'],
                    'fr' => ['name' => 'Demande d\'approbation', 'description' => 'Nouveau document en attente de votre validation'],
                ],
            ],
            [
                'code' => 'SDI_TRANSMISSION_ERROR',
                'channel' => 'email',
                'translations' => [
                    'it' => ['name' => 'Scarto Fattura SDI', 'description' => 'Notifica di scarto o mancata consegna dal Sistema di Interscambio'],
                    'en' => ['name' => 'SDI Transmission Rejection', 'description' => 'Rejection notice received from the Italian SDI exchange'],
                    'de' => ['name' => 'SDI-Übertragungsfehler', 'description' => 'Ablehnungsnachricht vom italienischen SDI-Austauschsystem'],
                    'fr' => ['name' => 'Rejet de transmission SDI', 'description' => 'Notification de rejet reçue du système d\'échange SDI italien'],
                ],
            ],
        ];

        DB::transaction(function () use ($types): void {
            foreach ($types as $typeData) {
                /** @var NotificationType $notifType */
                $notifType = NotificationType::updateOrCreate(
                    ['code' => $typeData['code']],
                    [
                        'channel' => $typeData['channel'],
                        'is_active' => true,
                    ]
                );

                foreach ($typeData['translations'] as $locale => $trans) {
                    NotificationTypeTranslation::updateOrCreate(
                        [
                            'notification_type_id' => $notifType->id,
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
