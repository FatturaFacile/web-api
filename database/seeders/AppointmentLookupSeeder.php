<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

class AppointmentLookupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            [
                'code' => 'appointment_status',
                'name' => 'Appointment Status',
                'description' => 'Lifecycle statuses for technician field appointments',
                'items' => [
                    [
                        'code' => 'scheduled',
                        'sort_order' => 1,
                        'is_default' => true,
                        'translations' => [
                            'en' => ['label' => 'Scheduled', 'description' => 'Appointment scheduled in calendar'],
                            'it' => ['label' => 'Pianificato', 'description' => 'Appuntamento pianificato in calendario'],
                            'de' => ['label' => 'Geplant', 'description' => 'Termin im Kalender geplant'],
                            'fr' => ['label' => 'Planifié', 'description' => 'Rendez-vous planifié dans le calendrier'],
                        ],
                    ],
                    [
                        'code' => 'confirmed',
                        'sort_order' => 2,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Confirmed', 'description' => 'Customer confirmed availability'],
                            'it' => ['label' => 'Confermato', 'description' => 'Il cliente ha confermato la disponibilità'],
                            'de' => ['label' => 'Bestätigt', 'description' => 'Kunde hat Verfügbarkeit bestätigt'],
                            'fr' => ['label' => 'Confirmé', 'description' => 'Le client a confirmé sa disponibilité'],
                        ],
                    ],
                    [
                        'code' => 'in_progress',
                        'sort_order' => 3,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'In Progress', 'description' => 'Technician is en route or on site'],
                            'it' => ['label' => 'In Corso', 'description' => 'Il tecnico è in viaggio o sul posto'],
                            'de' => ['label' => 'In Ausführung', 'description' => 'Techniker ist unterwegs oder vor Ort'],
                            'fr' => ['label' => 'En Cours', 'description' => 'Le technicien est en route ou sur place'],
                        ],
                    ],
                    [
                        'code' => 'completed',
                        'sort_order' => 4,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Completed', 'description' => 'Intervention completed, ready for report'],
                            'it' => ['label' => 'Completato', 'description' => 'Intervento completato, pronto per rapportino'],
                            'de' => ['label' => 'Abgeschlossen', 'description' => 'Einsatz abgeschlossen, bereit für Bericht'],
                            'fr' => ['label' => 'Terminé', 'description' => 'Intervention terminée, prête pour rapport'],
                        ],
                    ],
                    [
                        'code' => 'cancelled',
                        'sort_order' => 5,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'Cancelled', 'description' => 'Appointment cancelled'],
                            'it' => ['label' => 'Annullato', 'description' => 'Appuntamento annullato'],
                            'de' => ['label' => 'Storniert', 'description' => 'Termin storniert'],
                            'fr' => ['label' => 'Annulé', 'description' => 'Rendez-vous annulé'],
                        ],
                    ],
                    [
                        'code' => 'no_show',
                        'sort_order' => 6,
                        'is_default' => false,
                        'translations' => [
                            'en' => ['label' => 'No Show', 'description' => 'Customer was not present at site'],
                            'it' => ['label' => 'Non Presente', 'description' => 'Cliente assente all\'indirizzo convenuto'],
                            'de' => ['label' => 'Nicht Angetroffen', 'description' => 'Kunde war nicht vor Ort'],
                            'fr' => ['label' => 'Absent', 'description' => 'Le client était absent sur place'],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($categories as $categoryData) {
            /** @var LookupCategory $category */
            $category = LookupCategory::updateOrCreate(
                ['code' => $categoryData['code']],
                [
                    'name' => $categoryData['name'],
                    'description' => $categoryData['description'],
                    'is_system' => true,
                ]
            );

            foreach ($categoryData['items'] as $itemData) {
                /** @var LookupItem $item */
                $item = LookupItem::updateOrCreate(
                    [
                        'tenant_id' => null,
                        'category_id' => $category->id,
                        'code' => $itemData['code'],
                    ],
                    [
                        'sort_order' => $itemData['sort_order'],
                        'is_default' => $itemData['is_default'],
                        'is_system' => true,
                        'is_active' => true,
                    ]
                );

                foreach ($itemData['translations'] as $locale => $trans) {
                    LookupItemTranslation::updateOrCreate(
                        [
                            'tenant_id' => null,
                            'lookup_item_id' => $item->id,
                            'locale' => $locale,
                        ],
                        [
                            'label' => $trans['label'],
                            'description' => $trans['description'],
                        ]
                    );
                }
            }
        }
    }
}
