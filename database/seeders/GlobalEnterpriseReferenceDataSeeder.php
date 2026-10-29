<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LookupCategory;
use App\Models\LookupItem;
use App\Models\LookupItemTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Global Multilingual Reference Data Seeder.
 *
 * Populates global reference data (tenant_id = null) across 15 ERP functional domains
 * in 4 languages: Italian (it), English (en), German (de), French (fr).
 * Idempotent via updateOrCreate().
 */
class GlobalEnterpriseReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $domains = $this->getReferenceDomains();

        Schema::disableForeignKeyConstraints();

        try {
            LookupCategory::resolveConnection()->transaction(function () use ($domains): void {
                foreach ($domains as $categoryData) {
                    /** @var LookupCategory $category */
                    $category = LookupCategory::updateOrCreate(
                        ['code' => $categoryData['code']],
                        [
                            'name' => $categoryData['name'],
                            'description' => $categoryData['description'] ?? null,
                            'is_system' => true,
                        ]
                    );

                    foreach ($categoryData['items'] as $sortOrder => $itemData) {
                        /** @var LookupItem $item */
                        $item = LookupItem::updateOrCreate(
                            [
                                'tenant_id' => null,
                                'category_id' => $category->id,
                                'code' => $itemData['code'],
                            ],
                            [
                                'metadata' => $itemData['metadata'] ?? null,
                                'sort_order' => $sortOrder + 1,
                                'is_active' => true,
                                'is_default' => $itemData['is_default'] ?? false,
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
                                    'short_label' => $transData['short_label'] ?? null,
                                    'description' => $transData['description'] ?? null,
                                ]
                            );
                        }
                    }
                }
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getReferenceDomains(): array
    {
        return [
            // 1. CRM REFERENCE DATA
            [
                'code' => 'crm_lead_status',
                'name' => 'Stato Lead / Lead Statuses',
                'description' => 'Global qualification statuses for commercial leads',
                'items' => [
                    ['code' => 'NEW', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Nuovo', 'short_label' => 'Nuovo', 'description' => 'Lead appena acquisito'],
                        'en' => ['label' => 'New', 'short_label' => 'New', 'description' => 'Newly acquired lead'],
                        'de' => ['label' => 'Neu', 'short_label' => 'Neu', 'description' => 'Neu erfasster Lead'],
                        'fr' => ['label' => 'Nouveau', 'short_label' => 'Nouv', 'description' => 'Piste nouvellement acquise'],
                    ]],
                    ['code' => 'CONTACTED', 'translations' => [
                        'it' => ['label' => 'Contattato', 'short_label' => 'Contat', 'description' => 'Primo contatto commerciale effettuato'],
                        'en' => ['label' => 'Contacted', 'short_label' => 'Cont', 'description' => 'Initial outreach performed'],
                        'de' => ['label' => 'Kontaktiert', 'short_label' => 'Kont', 'description' => 'Erstkontakt hergestellt'],
                        'fr' => ['label' => 'Contacté', 'short_label' => 'Cont', 'description' => 'Premier contact effectué'],
                    ]],
                    ['code' => 'QUALIFIED', 'translations' => [
                        'it' => ['label' => 'Qualificato', 'short_label' => 'Qualif', 'description' => 'Requisiti di budget e autorità verificati'],
                        'en' => ['label' => 'Qualified', 'short_label' => 'Qual', 'description' => 'BANT requirements verified'],
                        'de' => ['label' => 'Qualifiziert', 'short_label' => 'Qual', 'description' => 'Bedarf und Budget verifiziert'],
                        'fr' => ['label' => 'Qualifié', 'short_label' => 'Qual', 'description' => 'Exigences budgétaires vérifiées'],
                    ]],
                    ['code' => 'UNQUALIFIED', 'translations' => [
                        'it' => ['label' => 'Non Qualificato', 'short_label' => 'NonQual', 'description' => 'Lead non idoneo o fuori target'],
                        'en' => ['label' => 'Unqualified', 'short_label' => 'Unqual', 'description' => 'Lead out of target or not eligible'],
                        'de' => ['label' => 'Nicht qualifiziert', 'short_label' => 'N.Qual', 'description' => 'Lead nicht geeignet'],
                        'fr' => ['label' => 'Non qualifié', 'short_label' => 'N.Qual', 'description' => 'Piste non éligible'],
                    ]],
                    ['code' => 'NURTURING', 'translations' => [
                        'it' => ['label' => 'In Coltivazione', 'short_label' => 'Nurt', 'description' => 'In attesa di maturazione opportunità'],
                        'en' => ['label' => 'Nurturing', 'short_label' => 'Nurt', 'description' => 'Long term relationship development'],
                        'de' => ['label' => 'Lead-Pflege', 'short_label' => 'Pflege', 'description' => 'Längerfristige Entwicklung'],
                        'fr' => ['label' => 'En maturation', 'short_label' => 'Matur', 'description' => 'Développement relationnel à long terme'],
                    ]],
                ],
            ],
            [
                'code' => 'crm_opportunity_status',
                'name' => 'Stato Opportunità / Opportunity Statuses',
                'description' => 'Pipeline stages for sales opportunities',
                'items' => [
                    ['code' => 'PROSPECTING', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Prospezione', 'short_label' => 'Prosp', 'description' => 'Identificazione opportunità'],
                        'en' => ['label' => 'Prospecting', 'short_label' => 'Prosp', 'description' => 'Opportunity discovery'],
                        'de' => ['label' => 'Interessentengewinnung', 'short_label' => 'Inter', 'description' => 'Chancenerkennung'],
                        'fr' => ['label' => 'Prospection', 'short_label' => 'Prosp', 'description' => 'Découverte de l\'opportunité'],
                    ]],
                    ['code' => 'QUALIFICATION', 'translations' => [
                        'it' => ['label' => 'Qualificazione', 'short_label' => 'Qualif', 'description' => 'Verifica tecnica e decisionale'],
                        'en' => ['label' => 'Qualification', 'short_label' => 'Qual', 'description' => 'Scope and decision maker confirmation'],
                        'de' => ['label' => 'Qualifizierung', 'short_label' => 'Qual', 'description' => 'Entscheider- und Umfangsbestätigung'],
                        'fr' => ['label' => 'Qualification', 'short_label' => 'Qual', 'description' => 'Vérification technique et décideurs'],
                    ]],
                    ['code' => 'PROPOSAL', 'translations' => [
                        'it' => ['label' => 'Proposta Presentata', 'short_label' => 'Prop', 'description' => 'Offerta commerciale consegnata'],
                        'en' => ['label' => 'Proposal Submitted', 'short_label' => 'Prop', 'description' => 'Formal proposal delivered'],
                        'de' => ['label' => 'Angebot abgegeben', 'short_label' => 'Ang', 'description' => 'Angebot vorgelegt'],
                        'fr' => ['label' => 'Proposition soumise', 'short_label' => 'Prop', 'description' => 'Offre formelle présentée'],
                    ]],
                    ['code' => 'NEGOTIATION', 'translations' => [
                        'it' => ['label' => 'Negoziazione', 'short_label' => 'Negoz', 'description' => 'Negotazione clausole e prezzi'],
                        'en' => ['label' => 'Negotiation', 'short_label' => 'Neg', 'description' => 'Terms and pricing review'],
                        'de' => ['label' => 'Verhandlung', 'short_label' => 'Verh', 'description' => 'Vertrags- und Preisverhandlung'],
                        'fr' => ['label' => 'Négociation', 'short_label' => 'Nég', 'description' => 'Négociation des conditions'],
                    ]],
                    ['code' => 'CLOSED_WON', 'translations' => [
                        'it' => ['label' => 'Chiusa Vinta', 'short_label' => 'Vinta', 'description' => 'Accordo commerciale siglato'],
                        'en' => ['label' => 'Closed Won', 'short_label' => 'Won', 'description' => 'Deal signed and won'],
                        'de' => ['label' => 'Erfolgreich abgeschlossen', 'short_label' => 'Gew', 'description' => 'Vertrag unterzeichnet'],
                        'fr' => ['label' => 'Gagnée', 'short_label' => 'Gagné', 'description' => 'Contrat signé'],
                    ]],
                    ['code' => 'CLOSED_LOST', 'translations' => [
                        'it' => ['label' => 'Chiusa Persa', 'short_label' => 'Persa', 'description' => 'Opportunità non convertita'],
                        'en' => ['label' => 'Closed Lost', 'short_label' => 'Lost', 'description' => 'Opportunity lost to competitor or cancelled'],
                        'de' => ['label' => 'Verloren', 'short_label' => 'Verl', 'description' => 'Verkaufschance verloren'],
                        'fr' => ['label' => 'Perdue', 'short_label' => 'Perdu', 'description' => 'Opportunité non convertie'],
                    ]],
                ],
            ],
            [
                'code' => 'crm_lead_source',
                'name' => 'Origine Lead / Lead Sources',
                'description' => 'Channels through which prospective clients arrive',
                'items' => [
                    ['code' => 'WEBSITE', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Sito Web', 'short_label' => 'Web', 'description' => 'Modulo di contatto o iscrizione online'],
                        'en' => ['label' => 'Website', 'short_label' => 'Web', 'description' => 'Online form or portal registration'],
                        'de' => ['label' => 'Webseite', 'short_label' => 'Web', 'description' => 'Kontaktformular oder Online-Registrierung'],
                        'fr' => ['label' => 'Site Web', 'short_label' => 'Web', 'description' => 'Formulaire en ligne'],
                    ]],
                    ['code' => 'REFERRAL', 'translations' => [
                        'it' => ['label' => 'Segnalazione / Passaparola', 'short_label' => 'Segn', 'description' => 'Raccomandazione da cliente o partner'],
                        'en' => ['label' => 'Referral', 'short_label' => 'Ref', 'description' => 'Recommendation by existing client or partner'],
                        'de' => ['label' => 'Empfehlung', 'short_label' => 'Empf', 'description' => 'Empfehlung durch Partner oder Kunden'],
                        'fr' => ['label' => 'Recommandation', 'short_label' => 'Recom', 'description' => 'Recommandation client ou partenaire'],
                    ]],
                    ['code' => 'TRADE_SHOW', 'translations' => [
                        'it' => ['label' => 'Fiera di Settore', 'short_label' => 'Fiera', 'description' => 'Evento espositivo o fiera industriale'],
                        'en' => ['label' => 'Trade Show / Expo', 'short_label' => 'Expo', 'description' => 'Industry convention or fair'],
                        'de' => ['label' => 'Messe', 'short_label' => 'Messe', 'description' => 'Fachmesse oder Konferenz'],
                        'fr' => ['label' => 'Salon Professionnel', 'short_label' => 'Salon', 'description' => 'Événement d\'exposition industrielle'],
                    ]],
                    ['code' => 'COLD_CALL', 'translations' => [
                        'it' => ['label' => 'Chiamata a Freddo / Outbound', 'short_label' => 'Outb', 'description' => 'Contatto telefonico outbound'],
                        'en' => ['label' => 'Cold Outreach', 'short_label' => 'Outb', 'description' => 'Outbound SDR outreach'],
                        'de' => ['label' => 'Kaltakquise', 'short_label' => 'Akq', 'description' => 'Direkte telefonische Kontaktaufnahme'],
                        'fr' => ['label' => 'Démarchage téléphonique', 'short_label' => 'Dém', 'description' => 'Prospection directe'],
                    ]],
                    ['code' => 'PARTNER', 'translations' => [
                        'it' => ['label' => 'Canale Partner', 'short_label' => 'Part', 'description' => 'Introdotto da rete distributori'],
                        'en' => ['label' => 'Partner Channel', 'short_label' => 'Part', 'description' => 'Introduced through reseller or affiliate'],
                        'de' => ['label' => 'Partnernetzwerk', 'short_label' => 'Part', 'description' => 'Über Vertriebspartner eingebracht'],
                        'fr' => ['label' => 'Réseau Partenaire', 'short_label' => 'Part', 'description' => 'Introduit par un distributeur'],
                    ]],
                ],
            ],
            [
                'code' => 'crm_opportunity_type',
                'name' => 'Tipo Opportunità / Opportunity Types',
                'description' => 'Categorization of opportunity engagement nature',
                'items' => [
                    ['code' => 'NEW_BUSINESS', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Nuovo Cliente', 'short_label' => 'Nuovo', 'description' => 'Acquisizione primo contratto'],
                        'en' => ['label' => 'New Business', 'short_label' => 'New', 'description' => 'New logo acquisition'],
                        'de' => ['label' => 'Neugeschäft', 'short_label' => 'Neu', 'description' => 'Neukundenakquise'],
                        'fr' => ['label' => 'Nouveau Client', 'short_label' => 'Nouv', 'description' => 'Acquisition premier contrat'],
                    ]],
                    ['code' => 'EXISTING_EXPANSION', 'translations' => [
                        'it' => ['label' => 'Espansione / Upsell', 'short_label' => 'Upsell', 'description' => 'Vendita licenze aggiuntive o add-on'],
                        'en' => ['label' => 'Expansion / Upsell', 'short_label' => 'Upsell', 'description' => 'Additional licenses or services to existing client'],
                        'de' => ['label' => 'Bestandskundenausbau', 'short_label' => 'Ausbau', 'description' => 'Zusatzlizenzen oder Erweiterung'],
                        'fr' => ['label' => 'Expansion / Vente incitative', 'short_label' => 'Upsell', 'description' => 'Vente de modules additionnels'],
                    ]],
                    ['code' => 'RENEWAL', 'translations' => [
                        'it' => ['label' => 'Rinnovo Contratto', 'short_label' => 'Rinnovo', 'description' => 'Rinnovo abbonamento o canone'],
                        'en' => ['label' => 'Contract Renewal', 'short_label' => 'Renew', 'description' => 'Contract or subscription renewal'],
                        'de' => ['label' => 'Vertragsverlängerung', 'short_label' => 'Verl', 'description' => 'Verlängerung von Wartung oder Abonnement'],
                        'fr' => ['label' => 'Renouvellement', 'short_label' => 'Renouv', 'description' => 'Renouvellement de contrat'],
                    ]],
                ],
            ],
            [
                'code' => 'crm_customer_category',
                'name' => 'Categoria Cliente / Customer Categories',
                'description' => 'Client segmentation tiers',
                'items' => [
                    ['code' => 'ENTERPRISE', 'translations' => [
                        'it' => ['label' => 'Grandi Imprese (Enterprise)', 'short_label' => 'ENT', 'description' => 'Fatturato > 50M o oltre 250 dipendenti'],
                        'en' => ['label' => 'Enterprise', 'short_label' => 'ENT', 'description' => 'Revenue > 50M or > 250 employees'],
                        'de' => ['label' => 'Großunternehmen', 'short_label' => 'ENT', 'description' => 'Umsatz > 50M oder > 250 Mitarbeiter'],
                        'fr' => ['label' => 'Grande Entreprise', 'short_label' => 'ENT', 'description' => 'CA > 50M ou > 250 salariés'],
                    ]],
                    ['code' => 'MID_MARKET', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Media Impresa', 'short_label' => 'MID', 'description' => 'Fatturato tra 10M e 50M'],
                        'en' => ['label' => 'Mid Market', 'short_label' => 'MID', 'description' => 'Revenue between 10M and 50M'],
                        'de' => ['label' => 'Gehobener Mittelstand', 'short_label' => 'MID', 'description' => 'Umsatz zwischen 10M und 50M'],
                        'fr' => ['label' => 'Moyenne Entreprise', 'short_label' => 'MID', 'description' => 'CA entre 10M et 50M'],
                    ]],
                    ['code' => 'SMB', 'translations' => [
                        'it' => ['label' => 'Piccola Impresa (PMI)', 'short_label' => 'PMI', 'description' => 'Fatturato inferiore a 10M'],
                        'en' => ['label' => 'Small Business (SMB)', 'short_label' => 'SMB', 'description' => 'Revenue under 10M'],
                        'de' => ['label' => 'Kleinunternehmen (KMU)', 'short_label' => 'KMU', 'description' => 'Umsatz unter 10M'],
                        'fr' => ['label' => 'PME', 'short_label' => 'PME', 'description' => 'CA inférieur à 10M'],
                    ]],
                ],
            ],
            [
                'code' => 'crm_activity_type',
                'name' => 'Tipo Attività CRM / Activity Types',
                'description' => 'Interaction touchpoint types with accounts and leads',
                'items' => [
                    ['code' => 'CALL', 'translations' => [
                        'it' => ['label' => 'Telefonata', 'short_label' => 'Tel', 'description' => 'Telefonata commerciale o di follow-up'],
                        'en' => ['label' => 'Phone Call', 'short_label' => 'Call', 'description' => 'Sales or follow-up phone call'],
                        'de' => ['label' => 'Telefonanruf', 'short_label' => 'Anruf', 'description' => 'Telefonischer Kontakt'],
                        'fr' => ['label' => 'Appel téléphonique', 'short_label' => 'Appel', 'description' => 'Appel de suivi'],
                    ]],
                    ['code' => 'MEETING', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Riunione / Meeting', 'short_label' => 'Meet', 'description' => 'Incontro di persona o videochiamata'],
                        'en' => ['label' => 'Meeting / Video Call', 'short_label' => 'Meet', 'description' => 'In person or virtual meeting'],
                        'de' => ['label' => 'Besprechung / Meeting', 'short_label' => 'Meet', 'description' => 'Persönliches Treffen oder Videocall'],
                        'fr' => ['label' => 'Réunion / Visioconférence', 'short_label' => 'Réun', 'description' => 'Entrevue physique ou virtuelle'],
                    ]],
                    ['code' => 'EMAIL', 'translations' => [
                        'it' => ['label' => 'Email', 'short_label' => 'Email', 'description' => 'Corrispondenza di posta elettronica'],
                        'en' => ['label' => 'Email', 'short_label' => 'Email', 'description' => 'Email correspondence'],
                        'de' => ['label' => 'E-Mail', 'short_label' => 'E-Mail', 'description' => 'E-Mail-Schriftverkehr'],
                        'fr' => ['label' => 'Courriel', 'short_label' => 'Courriel', 'description' => 'Correspondance par courriel'],
                    ]],
                    ['code' => 'DEMO', 'translations' => [
                        'it' => ['label' => 'Dimostrazione Prodotto', 'short_label' => 'Demo', 'description' => 'Sessione dimostrativa tecnica'],
                        'en' => ['label' => 'Product Demo', 'short_label' => 'Demo', 'description' => 'Product walkthrough and demonstration'],
                        'de' => ['label' => 'Produktdemonstration', 'short_label' => 'Demo', 'description' => 'Präsentation der Software/Lösung'],
                        'fr' => ['label' => 'Démonstration Produit', 'short_label' => 'Démo', 'description' => 'Démonstration technique'],
                    ]],
                ],
            ],

            // 2. SALES REFERENCE DATA
            [
                'code' => 'sales_quote_status',
                'name' => 'Stato Preventivo / Quote Statuses',
                'description' => 'Commercial quotation approval states',
                'items' => [
                    ['code' => 'DRAFT', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Bozza', 'short_label' => 'Bozza', 'description' => 'In fase di redazione'],
                        'en' => ['label' => 'Draft', 'short_label' => 'Draft', 'description' => 'In preparation'],
                        'de' => ['label' => 'Entwurf', 'short_label' => 'Entw', 'description' => 'In Bearbeitung'],
                        'fr' => ['label' => 'Brouillon', 'short_label' => 'Brouil', 'description' => 'En préparation'],
                    ]],
                    ['code' => 'SENT', 'translations' => [
                        'it' => ['label' => 'Inviato', 'short_label' => 'Inv', 'description' => 'Inviato al cliente per revisione'],
                        'en' => ['label' => 'Sent', 'short_label' => 'Sent', 'description' => 'Delivered to client for review'],
                        'de' => ['label' => 'Versendet', 'short_label' => 'Vers', 'description' => 'Dem Kunden zugestellt'],
                        'fr' => ['label' => 'Envoyé', 'short_label' => 'Env', 'description' => 'Transmis au client'],
                    ]],
                    ['code' => 'ACCEPTED', 'translations' => [
                        'it' => ['label' => 'Accettato', 'short_label' => 'Accett', 'description' => 'Accettato dal cliente'],
                        'en' => ['label' => 'Accepted', 'short_label' => 'Accept', 'description' => 'Accepted and signed by client'],
                        'de' => ['label' => 'Angenommen', 'short_label' => 'Angen', 'description' => 'Vom Kunden bestätigt'],
                        'fr' => ['label' => 'Accepté', 'short_label' => 'Accept', 'description' => 'Approuvé par le client'],
                    ]],
                    ['code' => 'REJECTED', 'translations' => [
                        'it' => ['label' => 'Rifiutato', 'short_label' => 'Rif', 'description' => 'Rifiutato dal cliente'],
                        'en' => ['label' => 'Rejected', 'short_label' => 'Reject', 'description' => 'Declined by client'],
                        'de' => ['label' => 'Abgelehnt', 'short_label' => 'Abgel', 'description' => 'Vom Kunden zurückgewiesen'],
                        'fr' => ['label' => 'Refusé', 'short_label' => 'Refus', 'description' => 'Rejeté par le client'],
                    ]],
                ],
            ],
            [
                'code' => 'sales_order_status',
                'name' => 'Stato Ordine di Vendita / Sales Order Statuses',
                'description' => 'Fulfillment and billing states for sales orders',
                'items' => [
                    ['code' => 'PENDING', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'In Attesa', 'short_label' => 'Att', 'description' => 'In attesa di approvazione o evasione'],
                        'en' => ['label' => 'Pending', 'short_label' => 'Pend', 'description' => 'Awaiting approval or allocation'],
                        'de' => ['label' => 'Ausstehend', 'short_label' => 'Ausst', 'description' => 'Wartet auf Freigabe'],
                        'fr' => ['label' => 'En attente', 'short_label' => 'Att', 'description' => 'En attente de traitement'],
                    ]],
                    ['code' => 'CONFIRMED', 'translations' => [
                        'it' => ['label' => 'Confermato', 'short_label' => 'Conf', 'description' => 'Ordine validato ed esecutivo'],
                        'en' => ['label' => 'Confirmed', 'short_label' => 'Conf', 'description' => 'Order validated for delivery'],
                        'de' => ['label' => 'Bestätigt', 'short_label' => 'Best', 'description' => 'Auftrag bestätigt'],
                        'fr' => ['label' => 'Confirmé', 'short_label' => 'Conf', 'description' => 'Commande confirmée'],
                    ]],
                    ['code' => 'SHIPPED', 'translations' => [
                        'it' => ['label' => 'Spedito', 'short_label' => 'Sped', 'description' => 'Merce affidata al corriere'],
                        'en' => ['label' => 'Shipped', 'short_label' => 'Ship', 'description' => 'Goods dispatched to carrier'],
                        'de' => ['label' => 'Versandt', 'short_label' => 'Vers', 'description' => 'Ware versendet'],
                        'fr' => ['label' => 'Expédié', 'short_label' => 'Expéd', 'description' => 'Marchandise expédiée'],
                    ]],
                    ['code' => 'DELIVERED', 'translations' => [
                        'it' => ['label' => 'Consegnato', 'short_label' => 'Cons', 'description' => 'Consegna completata e firmata'],
                        'en' => ['label' => 'Delivered', 'short_label' => 'Deliv', 'description' => 'Proof of delivery received'],
                        'de' => ['label' => 'Geliefert', 'short_label' => 'Gelief', 'description' => 'Erfolgreich zugestellt'],
                        'fr' => ['label' => 'Livré', 'short_label' => 'Livré', 'description' => 'Livraison confirmée'],
                    ]],
                ],
            ],

            // 3. PROCUREMENT REFERENCE DATA
            [
                'code' => 'procurement_supplier_category',
                'name' => 'Categoria Fornitore / Supplier Categories',
                'description' => 'Segmentation for vendor master data',
                'items' => [
                    ['code' => 'RAW_MATERIALS', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Materie Prime & Componenti', 'short_label' => 'MP', 'description' => 'Fornitori di metalli, polimeri e componenti'],
                        'en' => ['label' => 'Raw Materials & Components', 'short_label' => 'RM', 'description' => 'Raw material and industrial input vendors'],
                        'de' => ['label' => 'Rohstoffe & Komponenten', 'short_label' => 'Rohst', 'description' => 'Lieferanten für Produktionsmaterial'],
                        'fr' => ['label' => 'Matières premières & Composants', 'short_label' => 'MP', 'description' => 'Fournisseurs industriels'],
                    ]],
                    ['code' => 'SERVICES', 'translations' => [
                        'it' => ['label' => 'Servizi & Consulenze', 'short_label' => 'Serv', 'description' => 'Servizi professionali, IT e manutenzioni'],
                        'en' => ['label' => 'Services & Consulting', 'short_label' => 'Serv', 'description' => 'Professional, IT and maintenance services'],
                        'de' => ['label' => 'Dienstleistungen & Beratung', 'short_label' => 'Dienst', 'description' => 'IT- und professionelle Dienstleister'],
                        'fr' => ['label' => 'Services & Conseil', 'short_label' => 'Serv', 'description' => 'Services professionnels et IT'],
                    ]],
                    ['code' => 'LOGISTICS', 'translations' => [
                        'it' => ['label' => 'Logistica & Trasporti', 'short_label' => 'Log', 'description' => 'Spedizionieri e vettori'],
                        'en' => ['label' => 'Logistics & Freight', 'short_label' => 'Log', 'description' => 'Carriers and warehouse partners'],
                        'de' => ['label' => 'Logistik & Fracht', 'short_label' => 'Log', 'description' => 'Speditionen und Frachtführer'],
                        'fr' => ['label' => 'Logistique & Transport', 'short_label' => 'Log', 'description' => 'Transporteurs et transitaires'],
                    ]],
                ],
            ],
            [
                'code' => 'procurement_po_status',
                'name' => 'Stato Ordine di Acquisto / Purchase Order Statuses',
                'description' => 'Procurement lifecycle states',
                'items' => [
                    ['code' => 'DRAFT', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Bozza', 'short_label' => 'Bozza', 'description' => 'In preparazione dall\'ufficio acquisti'],
                        'en' => ['label' => 'Draft', 'short_label' => 'Draft', 'description' => 'Drafted by purchasing officer'],
                        'de' => ['label' => 'Entwurf', 'short_label' => 'Entw', 'description' => 'In Bearbeitung durch Einkauf'],
                        'fr' => ['label' => 'Brouillon', 'short_label' => 'Brouil', 'description' => 'En préparation par les achats'],
                    ]],
                    ['code' => 'APPROVED', 'translations' => [
                        'it' => ['label' => 'Approvato', 'short_label' => 'Appr', 'description' => 'Autorizzato secondo policy di spesa'],
                        'en' => ['label' => 'Approved', 'short_label' => 'Appr', 'description' => 'Authorized per spending delegation'],
                        'de' => ['label' => 'Freigegeben', 'short_label' => 'Freig', 'description' => 'Budgetfreigabe erteilt'],
                        'fr' => ['label' => 'Approuvé', 'short_label' => 'Appr', 'description' => 'Autorisé selon la politique d\'achat'],
                    ]],
                    ['code' => 'RECEIVED', 'translations' => [
                        'it' => ['label' => 'Ricevuto Completo', 'short_label' => 'Ric', 'description' => 'Merce interamente ricevuta a magazzino'],
                        'en' => ['label' => 'Fully Received', 'short_label' => 'Recv', 'description' => 'All items received into warehouse'],
                        'de' => ['label' => 'Vollständig eingegangen', 'short_label' => 'Eing', 'description' => 'Ware vollständig vereinnahmt'],
                        'fr' => ['label' => 'Entièrement reçu', 'short_label' => 'Reçu', 'description' => 'Marchandises réceptionnées'],
                    ]],
                ],
            ],

            // 4. INVENTORY REFERENCE DATA
            [
                'code' => 'inventory_warehouse_type',
                'name' => 'Tipo Magazzino / Warehouse Types',
                'description' => 'Classification of storage facilities',
                'items' => [
                    ['code' => 'CENTRAL_DC', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Centro Distribuzione Centrale', 'short_label' => 'CDC', 'description' => 'Magazzino centrale logistico'],
                        'en' => ['label' => 'Central Distribution Center', 'short_label' => 'CDC', 'description' => 'Primary distribution hub'],
                        'de' => ['label' => 'Zentrallager', 'short_label' => 'ZLog', 'description' => 'Zentrales Logistikzentrum'],
                        'fr' => ['label' => 'Centre de Distribution Principal', 'short_label' => 'CDP', 'description' => 'Entrepôt logistique central'],
                    ]],
                    ['code' => 'RAW_MATERIALS', 'translations' => [
                        'it' => ['label' => 'Magazzino Materie Prime', 'short_label' => 'MMP', 'description' => 'Stoccaggio componenti di produzione'],
                        'en' => ['label' => 'Raw Materials Warehouse', 'short_label' => 'RMW', 'description' => 'Manufacturing raw materials store'],
                        'de' => ['label' => 'Rohstofflager', 'short_label' => 'RohL', 'description' => 'Lager für Fertigungskomponenten'],
                        'fr' => ['label' => 'Entrepôt Matières Premières', 'short_label' => 'EMP', 'description' => 'Stockage intrants de fabrication'],
                    ]],
                    ['code' => 'FINISHED_GOODS', 'translations' => [
                        'it' => ['label' => 'Magazzino Prodotti Finiti', 'short_label' => 'MPF', 'description' => 'Stoccaggio prodotti pronti per la vendita'],
                        'en' => ['label' => 'Finished Goods Warehouse', 'short_label' => 'FGW', 'description' => 'Ready for delivery inventory store'],
                        'de' => ['label' => 'Fertigwarenlager', 'short_label' => 'FertL', 'description' => 'Lager für verkaufsfertige Erzeugnisse'],
                        'fr' => ['label' => 'Entrepôt Produits Finis', 'short_label' => 'EPF', 'description' => 'Stockage produits prêts à l\'expédition'],
                    ]],
                ],
            ],
            [
                'code' => 'inventory_status',
                'name' => 'Stato Inventario / Inventory Statuses',
                'description' => 'Lot and stock availability states',
                'items' => [
                    ['code' => 'AVAILABLE', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Disponibile', 'short_label' => 'Disp', 'description' => 'Giacenza libera per prelievo o produzione'],
                        'en' => ['label' => 'Available', 'short_label' => 'Avail', 'description' => 'Unrestricted stock on hand'],
                        'de' => ['label' => 'Verfügbar', 'short_label' => 'Verf', 'description' => 'Frei verwendbarer Bestand'],
                        'fr' => ['label' => 'Disponible', 'short_label' => 'Disp', 'description' => 'Stock disponible sans restriction'],
                    ]],
                    ['code' => 'RESERVED', 'translations' => [
                        'it' => ['label' => 'Riservato', 'short_label' => 'Ris', 'description' => 'Impegnato per ordine cliente o commessa'],
                        'en' => ['label' => 'Reserved', 'short_label' => 'Resv', 'description' => 'Allocated to sales or production orders'],
                        'de' => ['label' => 'Reserviert', 'short_label' => 'Res', 'description' => 'Für Auftrag reserviert'],
                        'fr' => ['label' => 'Réservé', 'short_label' => 'Rés', 'description' => 'Alloué à une commande spécifique'],
                    ]],
                    ['code' => 'QUARANTINED', 'translations' => [
                        'it' => ['label' => 'In Quarantena / Bloccato', 'short_label' => 'Quar', 'description' => 'In attesa di collaudo o non conforme'],
                        'en' => ['label' => 'Quarantined', 'short_label' => 'Quar', 'description' => 'Pending QA clearance or non-compliant'],
                        'de' => ['label' => 'Gesperrt / Quarantäne', 'short_label' => 'Gesp', 'description' => 'In Qualitätsprüfung'],
                        'fr' => ['label' => 'En Quarantaine', 'short_label' => 'Quar', 'description' => 'En attente de contrôle qualité'],
                    ]],
                ],
            ],

            // 5. PROJECT REFERENCE DATA
            [
                'code' => 'project_status',
                'name' => 'Stato Progetto / Project Statuses',
                'description' => 'Project execution milestones',
                'items' => [
                    ['code' => 'PLANNING', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'In Pianificazione', 'short_label' => 'Pianif', 'description' => 'Definizione budget e risorse'],
                        'en' => ['label' => 'Planning', 'short_label' => 'Plan', 'description' => 'Budgeting and resource planning'],
                        'de' => ['label' => 'Planung', 'short_label' => 'Plan', 'description' => 'Projektdefinition und Budgetierung'],
                        'fr' => ['label' => 'En Planification', 'short_label' => 'Plan', 'description' => 'Élaboration du budget et des ressources'],
                    ]],
                    ['code' => 'IN_PROGRESS', 'translations' => [
                        'it' => ['label' => 'In Esecuzione', 'short_label' => 'Esec', 'description' => 'Attività operative attive'],
                        'en' => ['label' => 'In Progress', 'short_label' => 'Active', 'description' => 'Active execution phase'],
                        'de' => ['label' => 'In Bearbeitung', 'short_label' => 'Aktiv', 'description' => 'Laufende Projektdurchführung'],
                        'fr' => ['label' => 'En Cours', 'short_label' => 'Cours', 'description' => 'Exécution des tâches en cours'],
                    ]],
                    ['code' => 'COMPLETED', 'translations' => [
                        'it' => ['label' => 'Completato', 'short_label' => 'Compl', 'description' => 'Consegna finale effettuata'],
                        'en' => ['label' => 'Completed', 'short_label' => 'Done', 'description' => 'Final delivery accepted'],
                        'de' => ['label' => 'Abgeschlossen', 'short_label' => 'Abg', 'description' => 'Projekt erfolgreich abgeschlossen'],
                        'fr' => ['label' => 'Terminé', 'short_label' => 'Term', 'description' => 'Projet livré et approuvé'],
                    ]],
                ],
            ],

            // 6. ASSET REFERENCE DATA
            [
                'code' => 'asset_class',
                'name' => 'Classe Cespiti / Asset Classes',
                'description' => 'Fixed asset classifications',
                'items' => [
                    ['code' => 'MACHINERY', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Impianti e Macchinari', 'short_label' => 'Macc', 'description' => 'Macchinari industriali e linee produttive'],
                        'en' => ['label' => 'Plant & Machinery', 'short_label' => 'Mach', 'description' => 'Industrial manufacturing machines'],
                        'de' => ['label' => 'Maschinen & Anlagen', 'short_label' => 'Masch', 'description' => 'Produktionsanlagen'],
                        'fr' => ['label' => 'Installations & Machines', 'short_label' => 'Mach', 'description' => 'Machines industrielles de fabrication'],
                    ]],
                    ['code' => 'IT_EQUIPMENT', 'translations' => [
                        'it' => ['label' => 'Attrezzature Informatiche', 'short_label' => 'IT', 'description' => 'Server, workstation e apparati di rete'],
                        'en' => ['label' => 'IT Equipment', 'short_label' => 'IT', 'description' => 'Servers, workstations and network devices'],
                        'de' => ['label' => 'IT-Ausstattung', 'short_label' => 'IT', 'description' => 'Server, Arbeitsplätze und Netzwerkgeräte'],
                        'fr' => ['label' => 'Matériel Informatique', 'short_label' => 'IT', 'description' => 'Serveurs et postes de travail'],
                    ]],
                    ['code' => 'VEHICLES', 'translations' => [
                        'it' => ['label' => 'Automezzi Aziendali', 'short_label' => 'Auto', 'description' => 'Furgoni commerciali e veicoli flotta'],
                        'en' => ['label' => 'Commercial Vehicles', 'short_label' => 'Veh', 'description' => 'Fleet vans and commercial vehicles'],
                        'de' => ['label' => 'Fuhrpark', 'short_label' => 'KFZ', 'description' => 'Nutzfahrzeuge und Firmenwagen'],
                        'fr' => ['label' => 'Véhicules Utilitaires', 'short_label' => 'Véh', 'description' => 'Flotte de camionnettes et véhicules'],
                    ]],
                ],
            ],

            // 7. SERVICE REFERENCE DATA
            [
                'code' => 'service_ticket_priority',
                'name' => 'Priorità Ticket Servizio / Ticket Priorities',
                'description' => 'Incident escalation priority thresholds',
                'items' => [
                    ['code' => 'LOW', 'translations' => [
                        'it' => ['label' => 'Bassa', 'short_label' => 'P4', 'description' => 'Richiesta informativa o impatto trascurabile'],
                        'en' => ['label' => 'Low', 'short_label' => 'P4', 'description' => 'Minor inquiry or negligible business impact'],
                        'de' => ['label' => 'Niedrig', 'short_label' => 'P4', 'description' => 'Geringe betriebliche Auswirkung'],
                        'fr' => ['label' => 'Basse', 'short_label' => 'P4', 'description' => 'Impact opérationnel négligeable'],
                    ]],
                    ['code' => 'MEDIUM', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Media', 'short_label' => 'P3', 'description' => 'Disguido limitato senza blocco operativo'],
                        'en' => ['label' => 'Medium', 'short_label' => 'P3', 'description' => 'Standard incident without complete stoppage'],
                        'de' => ['label' => 'Mittel', 'short_label' => 'P3', 'description' => 'Standardvorfall ohne Betriebsstillstand'],
                        'fr' => ['label' => 'Moyenne', 'short_label' => 'P3', 'description' => 'Incident standard sans arrêt de service'],
                    ]],
                    ['code' => 'HIGH', 'translations' => [
                        'it' => ['label' => 'Alta', 'short_label' => 'P2', 'description' => 'Impatto significativo sui processi aziendali'],
                        'en' => ['label' => 'High', 'short_label' => 'P2', 'description' => 'Substantial disruption to core operations'],
                        'de' => ['label' => 'Hoch', 'short_label' => 'P2', 'description' => 'Erhebliche Störung des Geschäftsbetriebs'],
                        'fr' => ['label' => 'Haute', 'short_label' => 'P2', 'description' => 'Perturbation majeure des opérations'],
                    ]],
                    ['code' => 'CRITICAL', 'translations' => [
                        'it' => ['label' => 'Critica / Emergenza', 'short_label' => 'P1', 'description' => 'Blocco totale della produzione o del servizio'],
                        'en' => ['label' => 'Critical', 'short_label' => 'P1', 'description' => 'Total plant or service outage'],
                        'de' => ['label' => 'Kritisch', 'short_label' => 'P1', 'description' => 'Vollständiger Betriebsausfall'],
                        'fr' => ['label' => 'Critique', 'short_label' => 'P1', 'description' => 'Arrêt total des activités'],
                    ]],
                ],
            ],

            // 8. HR REFERENCE DATA
            [
                'code' => 'hr_department',
                'name' => 'Reparto Aziendale / Departments',
                'description' => 'Organizational departments',
                'items' => [
                    ['code' => 'ENGINEERING', 'translations' => [
                        'it' => ['label' => 'Ingegneria & R&S', 'short_label' => 'R&S', 'description' => 'Sviluppo prodotti e progettazione'],
                        'en' => ['label' => 'Engineering & R&D', 'short_label' => 'R&D', 'description' => 'Product development and engineering'],
                        'de' => ['label' => 'Entwicklung & Konstruktion', 'short_label' => 'F&E', 'description' => 'Forschung und Produktentwicklung'],
                        'fr' => ['label' => 'Ingénierie & R&D', 'short_label' => 'R&D', 'description' => 'Recherche et développement produit'],
                    ]],
                    ['code' => 'OPERATIONS', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Produzione & Operations', 'short_label' => 'Ops', 'description' => 'Fabbricazione e logistica'],
                        'en' => ['label' => 'Manufacturing & Operations', 'short_label' => 'Ops', 'description' => 'Factory floor and logistics'],
                        'de' => ['label' => 'Produktion & Fertigung', 'short_label' => 'Fert', 'description' => 'Fertigung und Montage'],
                        'fr' => ['label' => 'Production & Opérations', 'short_label' => 'Ops', 'description' => 'Fabrication en usine et logistique'],
                    ]],
                    ['code' => 'FINANCE', 'translations' => [
                        'it' => ['label' => 'Amministrazione & Finanza', 'short_label' => 'Fin', 'description' => 'Contabilità, tesoreria e controllo'],
                        'en' => ['label' => 'Finance & Accounting', 'short_label' => 'Fin', 'description' => 'Financial reporting and treasury'],
                        'de' => ['label' => 'Finanzwesen & Controlling', 'short_label' => 'Fin', 'description' => 'Buchhaltung und Controlling'],
                        'fr' => ['label' => 'Finance & Comptabilité', 'short_label' => 'Fin', 'description' => 'Comptabilité et trésorerie'],
                    ]],
                ],
            ],

            // 9. SUBSCRIPTION REFERENCE DATA
            [
                'code' => 'sub_plan_type',
                'name' => 'Tipo Piano Abbonamento / Subscription Plan Types',
                'description' => 'Recurring service tiers',
                'items' => [
                    ['code' => 'BASIC', 'translations' => [
                        'it' => ['label' => 'Piano Base', 'short_label' => 'Base', 'description' => 'Funzionalità essenziali'],
                        'en' => ['label' => 'Basic Tier', 'short_label' => 'Basic', 'description' => 'Essential features package'],
                        'de' => ['label' => 'Basis-Tarif', 'short_label' => 'Basis', 'description' => 'Grundfunktionen'],
                        'fr' => ['label' => 'Formule Essentielle', 'short_label' => 'Base', 'description' => 'Fonctionnalités essentielles'],
                    ]],
                    ['code' => 'PRO', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Piano Professionale', 'short_label' => 'Pro', 'description' => 'Funzionalità avanzate per team'],
                        'en' => ['label' => 'Professional Tier', 'short_label' => 'Pro', 'description' => 'Advanced feature set for growing teams'],
                        'de' => ['label' => 'Profi-Tarif', 'short_label' => 'Pro', 'description' => 'Erweiterte Funktionen für Teams'],
                        'fr' => ['label' => 'Formule Professionnelle', 'short_label' => 'Pro', 'description' => 'Fonctions avancées'],
                    ]],
                    ['code' => 'ENTERPRISE', 'translations' => [
                        'it' => ['label' => 'Piano Enterprise', 'short_label' => 'ENT', 'description' => 'Massima scalabilità e supporto 24/7'],
                        'en' => ['label' => 'Enterprise Tier', 'short_label' => 'ENT', 'description' => 'Full scale with dedicated 24/7 SLA'],
                        'de' => ['label' => 'Enterprise-Tarif', 'short_label' => 'ENT', 'description' => 'Voller Umfang mit 24/7-SLA'],
                        'fr' => ['label' => 'Formule Entreprise', 'short_label' => 'ENT', 'description' => 'Accompagnement dédié et SLA 24/7'],
                    ]],
                ],
            ],

            // 10. DOCUMENT MANAGEMENT REFERENCES
            [
                'code' => 'doc_type',
                'name' => 'Tipo Documento / Document Types',
                'description' => 'Enterprise document classification',
                'items' => [
                    ['code' => 'CONTRACT', 'translations' => [
                        'it' => ['label' => 'Contratto Giuridico', 'short_label' => 'Contr', 'description' => 'Accordi legalmente vincolanti'],
                        'en' => ['label' => 'Legal Contract', 'short_label' => 'Contr', 'description' => 'Binding agreements with clients or partners'],
                        'de' => ['label' => 'Rechtsvertrag', 'short_label' => 'Vertr', 'description' => 'Vertragliche Vereinbarung'],
                        'fr' => ['label' => 'Contrat Juridique', 'short_label' => 'Contr', 'description' => 'Accords contractuels'],
                    ]],
                    ['code' => 'SPECIFICATION', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Specifica Tecnica', 'short_label' => 'Spec', 'description' => 'Disegni e specifiche di collaudo'],
                        'en' => ['label' => 'Technical Specification', 'short_label' => 'Spec', 'description' => 'Engineering schematics and specs'],
                        'de' => ['label' => 'Technische Spezifikation', 'short_label' => 'Spez', 'description' => 'Konstruktionszeichnungen'],
                        'fr' => ['label' => 'Spécification Technique', 'short_label' => 'Spéc', 'description' => 'Cahier des charges'],
                    ]],
                    ['code' => 'CERTIFICATE', 'translations' => [
                        'it' => ['label' => 'Certificato di Conformità', 'short_label' => 'Cert', 'description' => 'Attestazioni CE e qualità ISO'],
                        'en' => ['label' => 'Compliance Certificate', 'short_label' => 'Cert', 'description' => 'ISO/CE compliance documents'],
                        'de' => ['label' => 'Konformitätszertifikat', 'short_label' => 'Zert', 'description' => 'ISO- und CE-Zertifikate'],
                        'fr' => ['label' => 'Certificat de Conformité', 'short_label' => 'Cert', 'description' => 'Certificats ISO et normes'],
                    ]],
                ],
            ],

            // 11. MANUFACTURING REFERENCES
            [
                'code' => 'mfg_item_type',
                'name' => 'Tipo Articolo Manifatturiero / Manufacturing Item Types',
                'description' => 'Production item nature in BOMs',
                'items' => [
                    ['code' => 'RAW_MATERIAL', 'translations' => [
                        'it' => ['label' => 'Materia Prima', 'short_label' => 'MP', 'description' => 'Articolo acquistato e consumato nei processi'],
                        'en' => ['label' => 'Raw Material', 'short_label' => 'RM', 'description' => 'Purchased component consumed in assembly'],
                        'de' => ['label' => 'Rohstoff / Zukaufteil', 'short_label' => 'Rohst', 'description' => 'Beschafftes Material für Fertigung'],
                        'fr' => ['label' => 'Matière Première', 'short_label' => 'MP', 'description' => 'Composant acheté pour la fabrication'],
                    ]],
                    ['code' => 'SUBASSEMBLY', 'translations' => [
                        'it' => ['label' => 'Semilavorato / Sottoassieme', 'short_label' => 'Semi', 'description' => 'Gruppo fabbricato internamente con distinta propria'],
                        'en' => ['label' => 'Subassembly', 'short_label' => 'SubA', 'description' => 'Manufactured intermediate with its own BOM'],
                        'de' => ['label' => 'Baugruppe / Halbfabrikat', 'short_label' => 'Baug', 'description' => 'Zwischenerzeugnis mit Stückliste'],
                        'fr' => ['label' => 'Sous-ensemble', 'short_label' => 'SousE', 'description' => 'Élément intermédiaire assemblé en interne'],
                    ]],
                    ['code' => 'FINISHED_GOOD', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Prodotto Finito', 'short_label' => 'PF', 'description' => 'Articolo finito pronto per la spedizione commerciale'],
                        'en' => ['label' => 'Finished Good', 'short_label' => 'FG', 'description' => 'Final manufactured product ready for sale'],
                        'de' => ['label' => 'Fertigerzeugnis', 'short_label' => 'Fert', 'description' => 'Verkaufsfertiges Endprodukt'],
                        'fr' => ['label' => 'Produit Fini', 'short_label' => 'PF', 'description' => 'Produit final prêt à la commercialisation'],
                    ]],
                ],
            ],

            // 12. ACCOUNTING REFERENCES
            [
                'code' => 'gl_account_category',
                'name' => 'Categoria Conto Generale / General Ledger Categories',
                'description' => 'Statutory balance sheet and P&L groupings',
                'items' => [
                    ['code' => 'ASSET', 'translations' => [
                        'it' => ['label' => 'Attività Patrimoniali', 'short_label' => 'ATT', 'description' => 'Cassa, crediti, magazzino e cespiti'],
                        'en' => ['label' => 'Assets', 'short_label' => 'ASSET', 'description' => 'Cash, receivables, inventory, fixed assets'],
                        'de' => ['label' => 'Aktiva', 'short_label' => 'AKT', 'description' => 'Geldmittel, Forderungen, Vorräte und Anlagevermögen'],
                        'fr' => ['label' => 'Actifs', 'short_label' => 'ACT', 'description' => 'Trésorerie, créances, stocks et immobilisations'],
                    ]],
                    ['code' => 'LIABILITY', 'translations' => [
                        'it' => ['label' => 'Passività Patrimoniali', 'short_label' => 'PASS', 'description' => 'Debiti fornitori, banche e imposte'],
                        'en' => ['label' => 'Liabilities', 'short_label' => 'LIAB', 'description' => 'Accounts payable, loans and taxes'],
                        'de' => ['label' => 'Passiva / Verbindlichkeiten', 'short_label' => 'PASS', 'description' => 'Verbindlichkeiten und Kredite'],
                        'fr' => ['label' => 'Passifs', 'short_label' => 'PASS', 'description' => 'Dettes fournisseurs et emprunts'],
                    ]],
                    ['code' => 'EQUITY', 'translations' => [
                        'it' => ['label' => 'Patrimonio Netto', 'short_label' => 'PN', 'description' => 'Capitale sociale e riserve'],
                        'en' => ['label' => 'Equity', 'short_label' => 'EQ', 'description' => 'Share capital and retained earnings'],
                        'de' => ['label' => 'Eigenkapital', 'short_label' => 'EK', 'description' => 'Stammkapital und Rücklagen'],
                        'fr' => ['label' => 'Capitaux Propres', 'short_label' => 'CP', 'description' => 'Capital social et réserves'],
                    ]],
                    ['code' => 'REVENUE', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Ricavi di Vendita', 'short_label' => 'RIC', 'description' => 'Fatturato e proventi operativi'],
                        'en' => ['label' => 'Revenue', 'short_label' => 'REV', 'description' => 'Sales and operating income'],
                        'de' => ['label' => 'Erträge / Umsatzerlöse', 'short_label' => 'ERTR', 'description' => 'Betriebliche Erträge'],
                        'fr' => ['label' => 'Chiffre d\'Affaires / Revenus', 'short_label' => 'REV', 'description' => 'Ventes et produits d\'exploitation'],
                    ]],
                    ['code' => 'EXPENSE', 'translations' => [
                        'it' => ['label' => 'Costi di Esercizio', 'short_label' => 'COSTI', 'description' => 'Acquisti, stipendi, ammortamenti e servizi'],
                        'en' => ['label' => 'Expenses', 'short_label' => 'EXP', 'description' => 'COGS, salaries, depreciation and operating costs'],
                        'de' => ['label' => 'Aufwendungen / Kosten', 'short_label' => 'AUFW', 'description' => 'Materialaufwand und Personalkosten'],
                        'fr' => ['label' => 'Charges d\'Exploitation', 'short_label' => 'CHG', 'description' => 'Achats, salaires et amortissements'],
                    ]],
                ],
            ],

            // 13. TREASURY REFERENCES
            [
                'code' => 'tre_cash_flow_category',
                'name' => 'Categoria Flusso di Cassa / Cash Flow Categories',
                'description' => 'Cash flow statement classification',
                'items' => [
                    ['code' => 'OPERATING', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Attività Operativa', 'short_label' => 'OPER', 'description' => 'Incassi clienti e pagamenti fornitori/stipendi'],
                        'en' => ['label' => 'Operating Activities', 'short_label' => 'OPER', 'description' => 'Core operational cash inflows and outflows'],
                        'de' => ['label' => 'Operative Tätigkeit', 'short_label' => 'OPER', 'description' => 'Laufender Geschäftsbetrieb'],
                        'fr' => ['label' => 'Activités d\'Exploitation', 'short_label' => 'EXPL', 'description' => 'Flux liés à l\'exploitation courante'],
                    ]],
                    ['code' => 'INVESTING', 'translations' => [
                        'it' => ['label' => 'Attività di Investimento', 'short_label' => 'INV', 'description' => 'Acquisto o cessione cespiti e impianti'],
                        'en' => ['label' => 'Investing Activities', 'short_label' => 'INV', 'description' => 'CapEx and fixed asset transactions'],
                        'de' => ['label' => 'Investitionstätigkeit', 'short_label' => 'INV', 'description' => 'Kauf und Verkauf von Sachanlagen'],
                        'fr' => ['label' => 'Activités d\'Investissement', 'short_label' => 'INV', 'description' => 'Acquisitions et cessions d\'actifs'],
                    ]],
                    ['code' => 'FINANCING', 'translations' => [
                        'it' => ['label' => 'Attività Finanziaria', 'short_label' => 'FIN', 'description' => 'Finanziamenti, dividendi e rimborsi quota capitale'],
                        'en' => ['label' => 'Financing Activities', 'short_label' => 'FIN', 'description' => 'Loans, equity issuance and debt service'],
                        'de' => ['label' => 'Finanzierungstätigkeit', 'short_label' => 'FIN', 'description' => 'Kredite und Eigenkapitalmaßnahmen'],
                        'fr' => ['label' => 'Activités de Financement', 'short_label' => 'FIN', 'description' => 'Emprunts et capitaux'],
                    ]],
                ],
            ],

            // 14. WORKFLOW REFERENCES
            [
                'code' => 'wfk_category',
                'name' => 'Categoria Workflow / Workflow Categories',
                'description' => 'Business process orchestration domains',
                'items' => [
                    ['code' => 'PROCUREMENT_APPROVAL', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Approvazione Acquisti', 'short_label' => 'Acq', 'description' => 'Iter di firma per ordini di acquisto superiori a soglia'],
                        'en' => ['label' => 'Procurement Approval', 'short_label' => 'PO', 'description' => 'Sign-off chain for purchase orders exceeding thresholds'],
                        'de' => ['label' => 'Einkaufsfreigabe', 'short_label' => 'Eink', 'description' => 'Freigabeprozess für Bestellungen'],
                        'fr' => ['label' => 'Approbation des Achats', 'short_label' => 'Ach', 'description' => 'Circuit de validation des commandes fournisseurs'],
                    ]],
                    ['code' => 'ECO_APPROVAL', 'translations' => [
                        'it' => ['label' => 'Revisione Tecnica ECO', 'short_label' => 'ECO', 'description' => 'Approvazione modifiche a distinte base e cicli'],
                        'en' => ['label' => 'Engineering Change Order', 'short_label' => 'ECO', 'description' => 'Sign-off for BOM revisions and routings'],
                        'de' => ['label' => 'Änderungsauftrag (ECO)', 'short_label' => 'ECO', 'description' => 'Genehmigung technischer Änderungen'],
                        'fr' => ['label' => 'Ordre de Modification Technique', 'short_label' => 'ECO', 'description' => 'Validation des modifications de nomenclature'],
                    ]],
                ],
            ],

            // 15. BI & ANALYTICS REFERENCES
            [
                'code' => 'bi_dashboard_category',
                'name' => 'Categoria Dashboard BI / BI Dashboard Categories',
                'description' => 'Analytical executive dashboard categories',
                'items' => [
                    ['code' => 'EXECUTIVE', 'is_default' => true, 'translations' => [
                        'it' => ['label' => 'Direzionale / C-Level', 'short_label' => 'C-Level', 'description' => 'Metriche aggregate per CEO, CFO e COO'],
                        'en' => ['label' => 'Executive & C-Level', 'short_label' => 'Exec', 'description' => 'Strategic KPIs for CEO, CFO and COO'],
                        'de' => ['label' => 'Vorstand & Geschäftsführung', 'short_label' => 'GF', 'description' => 'Strategische Kennzahlen für das Management'],
                        'fr' => ['label' => 'Direction Générale', 'short_label' => 'Dir', 'description' => 'Indicateurs clés pour la direction'],
                    ]],
                    ['code' => 'OPERATIONS', 'translations' => [
                        'it' => ['label' => 'Operativo & Produzione', 'short_label' => 'Ops', 'description' => 'OEE, avanzamento ordini e scarti'],
                        'en' => ['label' => 'Operations & Shop Floor', 'short_label' => 'Ops', 'description' => 'OEE, order progress and scrap rates'],
                        'de' => ['label' => 'Betrieb & Fertigung', 'short_label' => 'Prod', 'description' => 'OEE, Ausschuss und Durchlaufzeiten'],
                        'fr' => ['label' => 'Opérations & Fabrication', 'short_label' => 'Ops', 'description' => 'TRS, cadence et conformité'],
                    ]],
                ],
            ],
        ];
    }
}
