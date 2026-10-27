<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PaymentMethod;
use App\Models\PaymentMethodTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $methods = [
            [
                'code' => 'SEPA_CREDIT_TRANSFER',
                'is_electronic' => true,
                'requires_iban' => true,
                'translations' => [
                    'it' => ['name' => 'Bonifico Bancario SEPA', 'description' => 'Bonifico su conto corrente bancario o postale tramite circuito SEPA'],
                    'en' => ['name' => 'SEPA Credit Transfer', 'description' => 'Wire transfer directly to bank account via the SEPA network'],
                    'de' => ['name' => 'SEPA-Überweisung', 'description' => 'Banküberweisung auf das Bankkonto über das SEPA-System'],
                    'fr' => ['name' => 'Virement bancaire SEPA', 'description' => 'Virement bancaire direct sur compte courant via le réseau SEPA'],
                ],
            ],
            [
                'code' => 'SWISS_QR_BILL',
                'is_electronic' => true,
                'requires_iban' => true,
                'translations' => [
                    'it' => ['name' => 'Swiss QR-Bill (Polizza QR)', 'description' => 'Pagamento svizzero con sezione pagamento QR e QR-IBAN / IBAN'],
                    'en' => ['name' => 'Swiss QR-Bill', 'description' => 'Swiss standard invoice with QR code payment part and structured reference'],
                    'de' => ['name' => 'Schweizer QR-Rechnung', 'description' => 'Schweizer Standard-Zahlteil mit QR-Code und strukturierter Referenz'],
                    'fr' => ['name' => 'QR-facture suisse', 'description' => 'Section paiement suisse avec QR code et référence structurée'],
                ],
            ],
            [
                'code' => 'SEPA_DIRECT_DEBIT',
                'is_electronic' => true,
                'requires_iban' => true,
                'translations' => [
                    'it' => ['name' => 'Addebito Diretto SEPA (SDD B2B/Core)', 'description' => 'Incasso tramite mandato continuativo di addebito diretto in conto'],
                    'en' => ['name' => 'SEPA Direct Debit (SDD)', 'description' => 'Automated account debit based on a signed SEPA mandate'],
                    'de' => ['name' => 'SEPA-Lastschrift (SDD)', 'description' => 'Automatischer Bankeinzug basierend auf einem erteilten SEPA-Mandat'],
                    'fr' => ['name' => 'Prélèvement automatique SEPA', 'description' => 'Encaissement automatique sur la base d\'un mandat SEPA signé'],
                ],
            ],
            [
                'code' => 'RIBA',
                'is_electronic' => true,
                'requires_iban' => true,
                'translations' => [
                    'it' => ['name' => 'Ricevuta Bancaria Elettronica (Ri.Ba.)', 'description' => 'Procedura interbancaria italiana di incasso commerciale'],
                    'en' => ['name' => 'Italian Electronic Bank Receipt (Ri.Ba.)', 'description' => 'Standard Italian interbank commercial trade receipt collection'],
                    'de' => ['name' => 'Elektronische Bankquittung (Ri.Ba.)', 'description' => 'Italienisches Interbanken-Inkassoverfahren für Handelsforderungen'],
                    'fr' => ['name' => 'Quittance bancaire électronique (Ri.Ba.)', 'description' => 'Procédure interbancaire italienne d\'encaissement commercial'],
                ],
            ],
            [
                'code' => 'CREDIT_CARD',
                'is_electronic' => true,
                'requires_iban' => false,
                'translations' => [
                    'it' => ['name' => 'Carta di Credito / Debito', 'description' => 'Transazione tramite circuito Visa, Mastercard o American Express'],
                    'en' => ['name' => 'Credit / Debit Card', 'description' => 'Online or in-person transaction via Visa, Mastercard or Amex'],
                    'de' => ['name' => 'Kredit- / Debitkarte', 'description' => 'Kartenzahlung über Visa, Mastercard oder American Express'],
                    'fr' => ['name' => 'Carte de crédit / débit', 'description' => 'Paiement électronique par carte bancaire Visa, Mastercard ou Amex'],
                ],
            ],
            [
                'code' => 'CASH',
                'is_electronic' => false,
                'requires_iban' => false,
                'translations' => [
                    'it' => ['name' => 'Contanti', 'description' => 'Pagamento immediato in banconote e monete entro i limiti di legge'],
                    'en' => ['name' => 'Cash', 'description' => 'Direct cash settlement subject to statutory cash transaction limits'],
                    'de' => ['name' => 'Bargeld', 'description' => 'Barzahlung unter Einhaltung der gesetzlichen Bargeldobergrenzen'],
                    'fr' => ['name' => 'Espèces', 'description' => 'Paiement direct en numéraire dans le respect des plafonds légaux'],
                ],
            ],
        ];

        DB::transaction(function () use ($methods): void {
            foreach ($methods as $methodData) {
                /** @var PaymentMethod $method */
                $method = PaymentMethod::updateOrCreate(
                    ['code' => $methodData['code']],
                    [
                        'is_electronic' => $methodData['is_electronic'],
                        'requires_iban' => $methodData['requires_iban'],
                        'is_active' => true,
                    ]
                );

                foreach ($methodData['translations'] as $locale => $trans) {
                    PaymentMethodTranslation::updateOrCreate(
                        [
                            'payment_method_id' => $method->id,
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
