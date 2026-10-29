<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\InventoryMovementType;
use App\Models\InventoryMovementTypeTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class InventoryMovementTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            [
                'code' => 'PURCHASE_RECEIPT',
                'direction' => 'in',
                'affects_cogs' => true,
                'translations' => [
                    'it' => ['name' => 'Carico da Ricevimento Fornitore', 'description' => 'Ingresso merci a magazzino da fornitore con documento di trasporto'],
                    'en' => ['name' => 'Goods Receipt from Vendor', 'description' => 'Stock inbound from vendor goods receipt / purchase delivery note'],
                    'de' => ['name' => 'Wareneingang vom Lieferanten', 'description' => 'Zugang von Lieferantenwaren mit Lieferschein ins Lager'],
                    'fr' => ['name' => 'Réception de marchandises fournisseur', 'description' => 'Entrée en stock suite à livraison fournisseur avec bon de livraison'],
                ],
            ],
            [
                'code' => 'SALE_SHIPMENT',
                'direction' => 'out',
                'affects_cogs' => true,
                'translations' => [
                    'it' => ['name' => 'Scarico per Spedizione a Cliente', 'description' => 'Uscita merci per vendita e consegna al cliente con DDT'],
                    'en' => ['name' => 'Customer Sales Shipment', 'description' => 'Stock outbound for customer delivery with delivery note'],
                    'de' => ['name' => 'Warenausgang an Kunden', 'description' => 'Abgang für Verkauf und Versand an Kunden mit Lieferschein'],
                    'fr' => ['name' => 'Expédition vente client', 'description' => 'Sortie de stock pour livraison client avec bon d\'expédition'],
                ],
            ],
            [
                'code' => 'CYCLE_COUNT_GAIN',
                'direction' => 'in',
                'affects_cogs' => true,
                'translations' => [
                    'it' => ['name' => 'Rettifica Inventariale Positiva (Surplus)', 'description' => 'Carico a seguito di rilevazione inventario fisico superiore al sistema'],
                    'en' => ['name' => 'Physical Inventory Gain', 'description' => 'Positive adjustment arising from physical cycle counting surplus'],
                    'de' => ['name' => 'Inventurgewinn (Bestandsüberschuss)', 'description' => 'Positive Korrekturbuchung nach physischer Bestandsaufnahme'],
                    'fr' => ['name' => 'Gain d\'inventaire physique (Surplus)', 'description' => 'Ajustement positif suite à inventaire physique constatant un excédent'],
                ],
            ],
            [
                'code' => 'CYCLE_COUNT_LOSS',
                'direction' => 'out',
                'affects_cogs' => true,
                'translations' => [
                    'it' => ['name' => 'Rettifica Inventariale Negativa (Ammanco)', 'description' => 'Scarico a seguito di ammanco rilevato in sede di inventario'],
                    'en' => ['name' => 'Physical Inventory Loss / Shrinkage', 'description' => 'Negative inventory adjustment due to count variance or loss'],
                    'de' => ['name' => 'Inventurverlust (Bestandsschwund)', 'description' => 'Negative Bestandsanpassung wegen Inventurdifferenzen'],
                    'fr' => ['name' => 'Perte d\'inventaire physique (Déficit)', 'description' => 'Ajustement négatif suite à écart constaté lors de l\'inventaire'],
                ],
            ],
            [
                'code' => 'SCRAP_DAMAGED',
                'direction' => 'out',
                'affects_cogs' => true,
                'translations' => [
                    'it' => ['name' => 'Scarico per Rottamazione / Danneggiamento', 'description' => 'Scarico definitivo per materiale obsoleto, scaduto o danneggiato'],
                    'en' => ['name' => 'Scrap / Damaged Goods Write-off', 'description' => 'Disposal of defective, expired, or damaged inventory'],
                    'de' => ['name' => 'Ausschuss / Verschrottung', 'description' => 'Ausbuchung von beschädigten, abgelaufenen oder unbrauchbaren Gütern'],
                    'fr' => ['name' => 'Mise au rebut / Dépréciation', 'description' => 'Sortie définitive pour marchandise abîmée, obsolète ou détériorée'],
                ],
            ],
            [
                'code' => 'INTERNAL_TRANSFER',
                'direction' => 'transfer',
                'affects_cogs' => false,
                'translations' => [
                    'it' => ['name' => 'Trasferimento tra Magazzini', 'description' => 'Movimentazione interna di merce da una sede logistica ad un\'altra'],
                    'en' => ['name' => 'Internal Warehouse Transfer', 'description' => 'Inter-branch stock reallocation between logistics locations'],
                    'de' => ['name' => 'Interne Lagerumbuchung', 'description' => 'Warenbewegung zwischen verschiedenen Lagerstandorten'],
                    'fr' => ['name' => 'Transfert interne inter-dépôts', 'description' => 'Déplacement de marchandises d\'un entrepôt vers un autre'],
                ],
            ],
        ];

        DB::transaction(function () use ($types): void {
            foreach ($types as $typeData) {
                /** @var InventoryMovementType $type */
                $type = InventoryMovementType::updateOrCreate(
                    ['code' => $typeData['code']],
                    [
                        'direction' => $typeData['direction'],
                        'affects_cogs' => $typeData['affects_cogs'],
                        'is_active' => true,
                    ]
                );

                foreach ($typeData['translations'] as $locale => $trans) {
                    InventoryMovementTypeTranslation::updateOrCreate(
                        [
                            'movement_type_id' => $type->id,
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
