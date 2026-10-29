<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\DocumentStatus;
use App\Models\DocumentStatusTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentStatusSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $statuses = [
            // Invoices
            [
                'scope' => 'invoice',
                'code' => 'draft',
                'color_badge' => '#94A3B8',
                'sort_order' => 10,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Bozza', 'description' => 'Fattura in preparazione, non ancora fiscalmente emessa'],
                    'en' => ['label' => 'Draft', 'description' => 'Invoice in draft state, not yet fiscally finalized'],
                    'de' => ['label' => 'Entwurf', 'description' => 'Rechnung in Vorbereitung, noch nicht steuerlich verbucht'],
                    'fr' => ['label' => 'Brouillon', 'description' => 'Facture en préparation, non encore émise fiscalement'],
                ],
            ],
            [
                'scope' => 'invoice',
                'code' => 'issued',
                'color_badge' => '#3B82F6',
                'sort_order' => 20,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Emessa', 'description' => 'Fattura finalizzata e trasmessa al cliente o al sistema SDI'],
                    'en' => ['label' => 'Issued', 'description' => 'Invoice finalized and transmitted to customer or tax portal'],
                    'de' => ['label' => 'Ausgestellt', 'description' => 'Rechnung finalisiert und an Kunden oder Finanzamt übermittelt'],
                    'fr' => ['label' => 'Émise', 'description' => 'Facture finalisée et transmise au client ou portail fiscal'],
                ],
            ],
            [
                'scope' => 'invoice',
                'code' => 'partially_paid',
                'color_badge' => '#F59E0B',
                'sort_order' => 30,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Parzialmente Pagata', 'description' => 'Incasso parziale registrato, residuo in sospeso'],
                    'en' => ['label' => 'Partially Paid', 'description' => 'Partial settlement received, remaining balance outstanding'],
                    'de' => ['label' => 'Teilweise bezahlt', 'description' => 'Teilzahlung verbucht, Restbetrag noch offen'],
                    'fr' => ['label' => 'Partiellement payée', 'description' => 'Paiement partiel enregistré, solde restant dû'],
                ],
            ],
            [
                'scope' => 'invoice',
                'code' => 'paid',
                'color_badge' => '#10B981',
                'sort_order' => 40,
                'is_terminal' => true,
                'translations' => [
                    'it' => ['label' => 'Saldata', 'description' => 'Fattura interamente saldata e riconciliata'],
                    'en' => ['label' => 'Paid', 'description' => 'Invoice fully settled and bank-reconciled'],
                    'de' => ['label' => 'Bezahlt', 'description' => 'Rechnung vollständig beglichen und abgeglichen'],
                    'fr' => ['label' => 'Payée', 'description' => 'Facture intégralement réglée et rapprochée'],
                ],
            ],
            [
                'scope' => 'invoice',
                'code' => 'cancelled',
                'color_badge' => '#EF4444',
                'sort_order' => 99,
                'is_terminal' => true,
                'translations' => [
                    'it' => ['label' => 'Stornata / Annullata', 'description' => 'Documento annullato mediante nota di variazione'],
                    'en' => ['label' => 'Cancelled / Void', 'description' => 'Document voided or neutralized via credit note'],
                    'de' => ['label' => 'Storniert', 'description' => 'Rechnung storniert oder durch Gutschrift neutralisiert'],
                    'fr' => ['label' => 'Annulée', 'description' => 'Facture annulée ou régularisée par un avoir'],
                ],
            ],

            // Quotations
            [
                'scope' => 'quote',
                'code' => 'draft',
                'color_badge' => '#94A3B8',
                'sort_order' => 10,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Bozza', 'description' => 'Preventivo in fase di compilazione interna'],
                    'en' => ['label' => 'Draft', 'description' => 'Quotation being prepared internally'],
                    'de' => ['label' => 'Entwurf', 'description' => 'Angebot in interner Ausarbeitung'],
                    'fr' => ['label' => 'Brouillon', 'description' => 'Devis en cours de rédaction interne'],
                ],
            ],
            [
                'scope' => 'quote',
                'code' => 'sent',
                'color_badge' => '#3B82F6',
                'sort_order' => 20,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Inviato', 'description' => 'Inviato al potenziale cliente, in attesa di decisione'],
                    'en' => ['label' => 'Sent', 'description' => 'Submitted to prospective client, pending evaluation'],
                    'de' => ['label' => 'Versendet', 'description' => 'An Interessenten übermittelt, Entscheidung ausstehend'],
                    'fr' => ['label' => 'Envoyé', 'description' => 'Transmis au prospect, en attente de décision'],
                ],
            ],
            [
                'scope' => 'quote',
                'code' => 'accepted',
                'color_badge' => '#10B981',
                'sort_order' => 30,
                'is_terminal' => true,
                'translations' => [
                    'it' => ['label' => 'Accettato', 'description' => 'Offerta confermata e pronta per conversione in ordine'],
                    'en' => ['label' => 'Accepted', 'description' => 'Quotation accepted by client, ready for sales order'],
                    'de' => ['label' => 'Angenommen', 'description' => 'Angebot angenommen, bereit für Auftragsbestätigung'],
                    'fr' => ['label' => 'Accepté', 'description' => 'Devis accepté par le client, prêt pour commande'],
                ],
            ],
            [
                'scope' => 'quote',
                'code' => 'rejected',
                'color_badge' => '#EF4444',
                'sort_order' => 40,
                'is_terminal' => true,
                'translations' => [
                    'it' => ['label' => 'Rifiutato', 'description' => 'Offerta declinata dal cliente'],
                    'en' => ['label' => 'Rejected', 'description' => 'Quotation declined by client'],
                    'de' => ['label' => 'Abgelehnt', 'description' => 'Angebot vom Kunden abgelehnt'],
                    'fr' => ['label' => 'Refusé', 'description' => 'Devis décliné par le client'],
                ],
            ],

            // Sales Orders
            [
                'scope' => 'sales_order',
                'code' => 'confirmed',
                'color_badge' => '#3B82F6',
                'sort_order' => 10,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Confermato', 'description' => 'Ordine cliente confermato ed inserito a planning'],
                    'en' => ['label' => 'Confirmed', 'description' => 'Sales order confirmed and scheduled'],
                    'de' => ['label' => 'Bestätigt', 'description' => 'Kundenauftrag bestätigt und eingeplant'],
                    'fr' => ['label' => 'Confirmé', 'description' => 'Commande client confirmée et planifiée'],
                ],
            ],
            [
                'scope' => 'sales_order',
                'code' => 'in_production',
                'color_badge' => '#8B5CF6',
                'sort_order' => 20,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'In Lavorazione', 'description' => 'Ordine in produzione o preparazione logistica'],
                    'en' => ['label' => 'In Processing', 'description' => 'Order in production or warehouse picking'],
                    'de' => ['label' => 'In Bearbeitung', 'description' => 'Auftrag in Fertigung oder Kommissionierung'],
                    'fr' => ['label' => 'En cours', 'description' => 'Commande en production ou préparation logistique'],
                ],
            ],
            [
                'scope' => 'sales_order',
                'code' => 'shipped',
                'color_badge' => '#06B6D4',
                'sort_order' => 30,
                'is_terminal' => false,
                'translations' => [
                    'it' => ['label' => 'Spedito', 'description' => 'Merce spedita e documentata da DDT'],
                    'en' => ['label' => 'Shipped', 'description' => 'Goods dispatched with delivery note / packing slip'],
                    'de' => ['label' => 'Versendet', 'description' => 'Ware mit Lieferschein versendet'],
                    'fr' => ['label' => 'Expédié', 'description' => 'Marchandise expédiée avec bon de livraison'],
                ],
            ],
            [
                'scope' => 'sales_order',
                'code' => 'completed',
                'color_badge' => '#10B981',
                'sort_order' => 40,
                'is_terminal' => true,
                'translations' => [
                    'it' => ['label' => 'Completato', 'description' => 'Ordine completamente consegnato e fatturato'],
                    'en' => ['label' => 'Completed', 'description' => 'Order fully fulfilled and invoiced'],
                    'de' => ['label' => 'Abgeschlossen', 'description' => 'Auftrag vollständig geliefert und abgerechnet'],
                    'fr' => ['label' => 'Terminé', 'description' => 'Commande intégralement livrée et facturée'],
                ],
            ],
        ];

        DB::transaction(function () use ($statuses): void {
            foreach ($statuses as $statusData) {
                /** @var DocumentStatus $status */
                $status = DocumentStatus::updateOrCreate(
                    [
                        'scope' => $statusData['scope'],
                        'code' => $statusData['code'],
                    ],
                    [
                        'color_badge' => $statusData['color_badge'],
                        'sort_order' => $statusData['sort_order'],
                        'is_terminal' => $statusData['is_terminal'],
                    ]
                );

                foreach ($statusData['translations'] as $locale => $trans) {
                    DocumentStatusTranslation::updateOrCreate(
                        [
                            'document_status_id' => $status->id,
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
