<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;

final class CollectionTypeSeeder extends Seeder
{
    public function run(): void
    {
        $category = LookupCategory::updateOrCreate(
            ['code' => 'collection_type'],
            [
                'name' => 'Collection Types (Tipologie di Incasso)',
                'description' => 'Receivable collection and inbound settlement types',
                'is_system' => true,
            ]
        );

        $items = [
            [
                'code' => 'SEPA_DIRECT_DEBIT_CORE',
                'is_default' => true,
                'translations' => [
                    'it' => ['label' => 'Addebito Diretto SEPA (SDD Core)', 'description' => 'Incasso ricorrente B2C/B2B (pain.008)'],
                    'en' => ['label' => 'SEPA Direct Debit (SDD Core)', 'description' => 'Standard direct debit mandate collection (pain.008)'],
                    'de' => ['label' => 'SEPA-Basislastschrift (SDD Core)', 'description' => 'Standard-Lastschrifteinzug (pain.008)'],
                    'fr' => ['label' => 'Prélèvement SEPA (SDD Core)', 'description' => 'Prélèvement automatique standard (pain.008)'],
                ],
            ],
            [
                'code' => 'SEPA_DIRECT_DEBIT_B2B',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Addebito Diretto SEPA B2B', 'description' => 'Incasso non stornabile tra imprese'],
                    'en' => ['label' => 'SEPA Direct Debit B2B', 'description' => 'Irrevocable business-to-business direct debit'],
                    'de' => ['label' => 'SEPA-Firmenlastschrift B2B', 'description' => 'Unwiderrufliche B2B-Lastschrift'],
                    'fr' => ['label' => 'Prélèvement SEPA interentreprises B2B', 'description' => 'Prélèvement interentreprises irrévocable'],
                ],
            ],
            [
                'code' => 'WIRE_INBOUND',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Bonifico Ricevuto in Entrata', 'description' => 'Accredito bonifico da cliente'],
                    'en' => ['label' => 'Inbound Bank Wire', 'description' => 'Direct customer bank transfer credit'],
                    'de' => ['label' => 'Eingehende Überweisung', 'description' => 'Direkte Kundengutschrift per Überweisung'],
                    'fr' => ['label' => 'Virement bancaire entrant', 'description' => 'Crédit de virement direct du client'],
                ],
            ],
            [
                'code' => 'RIBA_COLLECTION',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Ricevuta Bancaria Attiva (Ri.Ba.)', 'description' => 'Flusso di presentazione Ri.Ba. su banca assuntrice'],
                    'en' => ['label' => 'Ri.Ba. Inbound Collection', 'description' => 'Electronic bank receipt collection file'],
                    'de' => ['label' => 'Ri.Ba.-Einzug', 'description' => 'Italienischer Wechselbeleg-Einzug'],
                    'fr' => ['label' => 'Encaissement Ri.Ba.', 'description' => 'Reçu bancaire électronique débiteur'],
                ],
            ],
            [
                'code' => 'CREDIT_CARD_GATEWAY',
                'is_default' => false,
                'translations' => [
                    'it' => ['label' => 'Gateway Carta di Credito', 'description' => 'Incasso digitale tramite POS virtuale o Stripe'],
                    'en' => ['label' => 'Credit Card / Digital Gateway', 'description' => 'E-commerce settlement via Stripe / Nexi / Adyen'],
                    'de' => ['label' => 'Kreditkarten-Gateway', 'description' => 'Digitale Abwicklung über Zahlungs-Gateway'],
                    'fr' => ['label' => 'Passerelle de carte bancaire', 'description' => 'Règlement en ligne par carte de crédit'],
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
