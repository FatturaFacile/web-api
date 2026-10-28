<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class PaymentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $category = LookupCategory::updateOrCreate(
            ['code' => 'payment_type'],
            [
                'name' => 'Payment Types (Metodi di Pagamento Tesoreria)',
                'description' => 'ISO 20022 and banking payment clearing types',
                'is_system' => true,
            ]
        );

        $items = [
            [
                'code' => 'SEPA_CREDIT_TRANSFER',
                'is_default' => true,
                'translations' => [
                    'it' => ['label' => 'Bonifico SEPA (pain.001)', 'description' => 'Bonifico bancario standard area SEPA'],
                    'en' => ['label' => 'SEPA Credit Transfer (pain.001)', 'description' => 'Standard euro credit transfer in SEPA area'],
                    'de' => ['label' => 'SEPA-Überweisung (pain.001)', 'description' => 'Standardüberweisung im SEPA-Raum'],
                    'fr' => ['label' => 'Virement SEPA (pain.001)', 'description' => 'Virement bancaire européen standard'],
                ],
            ],
            [
                'code' => 'SEPA_INSTANT',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Bonifico Istantaneo SEPA', 'description' => 'Regolamento immediato entro 10 secondi'],
                    'en' => ['label' => 'SEPA Instant Credit Transfer', 'description' => 'Instant clearing within 10 seconds'],
                    'de' => ['label' => 'SEPA-Echtzeitüberweisung', 'description' => 'Echtzeit-Gutschrift innerhalb von 10 Sekunden'],
                    'fr' => ['label' => 'Virement instantané SEPA', 'description' => 'Règlement immédiat en 10 secondes'],
                ],
            ],
            [
                'code' => 'URGENT_TARGET2',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Bonifico Urgente / TARGET2', 'description' => 'Regolamento lordo in tempo reale ad alto valore'],
                    'en' => ['label' => 'Urgent Wire / TARGET2', 'description' => 'Real-time gross settlement high-value transfer'],
                    'de' => ['label' => 'Eilüberweisung / TARGET2', 'description' => 'Echtzeit-Bruttoabwicklung für Großbeträge'],
                    'fr' => ['label' => 'Virement urgent / TARGET2', 'description' => 'Règlement brut en temps réel de montant élevé'],
                ],
            ],
            [
                'code' => 'SWIFT_MT103',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Bonifico Estero Extra-SEPA (SWIFT)', 'description' => 'Messaggio SWIFT MT103 / pacs.008'],
                    'en' => ['label' => 'International Wire (SWIFT MT103)', 'description' => 'Cross-border wire transfer via SWIFT network'],
                    'de' => ['label' => 'Auslandsüberweisung (SWIFT MT103)', 'description' => 'Grenzüberschreitender SWIFT-Zahlungsverkehr'],
                    'fr' => ['label' => 'Virement international (SWIFT MT103)', 'description' => 'Transfert international via le réseau SWIFT'],
                ],
            ],
            [
                'code' => 'F24_TAX',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Modello F24 Tributario', 'description' => 'Versamento imposte, contributi e tributi erariali'],
                    'en' => ['label' => 'F24 Tax Payment', 'description' => 'Italian statutory tax and social security payment'],
                    'de' => ['label' => 'F24-Steuerzahlung', 'description' => 'Italienische gesetzliche Steuer- und Abgabenabwicklung'],
                    'fr' => ['label' => 'Paiement fiscal F24', 'description' => 'Paiement fiscal et social italien officiel'],
                ],
            ],
            [
                'code' => 'RIBA_PAYMENT',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Ricevuta Bancaria Elettronica (Ri.Ba.)', 'description' => 'Disposizione di pagamento Ri.Ba.'],
                    'en' => ['label' => 'Electronic Bank Receipt (Ri.Ba.)', 'description' => 'Italian domestic electronic bank receipt'],
                    'de' => ['label' => 'Elektronische Bankquittung (Ri.Ba.)', 'description' => 'Italienische Bankquittung'],
                    'fr' => ['label' => 'Reçu bancaire électronique (Ri.Ba.)', 'description' => 'Reçu bancaire électronique italien'],
                ],
            ],
        ];

        foreach ($items as $sortOrder => $itemData) {
            $item = LookupItem::updateOrCreate(
                [
                    'tenant_id' => null,
                    'category_id' => $category->id,
                    'code' => $itemData['code'],
                ],
                [
                    'sort_order' => $sortOrder + 1,
                    'is_active' => true,
                    'is_default' => $itemData['is_default'],
                    'is_system' => true,
                ]
            );

            foreach ($itemData['translations'] as $locale => $transData) {
                LookupItemTranslation::updateOrCreate(
                    [
                        'tenant_id' => null,
                        'lookup_item_id' => $item->id,
                        'locale' => $locale,
                    ],
                    [
                        'label' => $transData['label'],
                        'description' => $transData['description'] ?? null,
                    ]
                );
            }
        }
    }
}
