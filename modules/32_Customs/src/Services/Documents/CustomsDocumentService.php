<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Documents;

use App\Actions\Customs\GenerateInvoiceOriginDeclarationAction;
use App\Contracts\PdfGenerator;
use App\Enums\Incoterm;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\ForeignTrade\CrossBorderVatRegimeResolver;
use App\Services\ForeignTrade\CustomsValueCalculator;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsSettings;

/**
 * Renders the bilingual customs documents of a declaration: the commercial / pro-forma invoice for customs (Italian with
 * English or German) and the T1 / T2 transit tracking sheet. They are working documents of the company, not documents
 * issued by customs.
 */
final class CustomsDocumentService
{
    /** Language pairs: the first language is always Italian. */
    public const array LanguagePairs = ['it_en' => ['it', 'en'], 'it_de' => ['it', 'de'], 'it_fr' => ['it', 'fr']];

    /**
     * @var array<string, array{it: string, en: string, de: string, fr: string}>
     */
    private const array LABELS = [
        'title' => ['it' => 'Fattura commerciale / pro-forma per la dogana', 'en' => 'Commercial / pro-forma invoice for customs', 'de' => 'Handels- / Proforma-Rechnung für den Zoll', 'fr' => 'Facture commerciale / pro forma pour la douane'],
        'exporter' => ['it' => 'Esportatore', 'en' => 'Exporter', 'de' => 'Exporteur', 'fr' => 'Exportateur'],
        'importer' => ['it' => 'Importatore / destinatario', 'en' => 'Importer / consignee', 'de' => 'Importeur / Empfänger', 'fr' => 'Importateur / destinataire'],
        'identification' => ['it' => 'EORI / UID', 'en' => 'EORI / UID', 'de' => 'EORI / UID', 'fr' => 'EORI / IDE'],
        'incoterms' => ['it' => 'Resa (Incoterms)', 'en' => 'Terms of delivery (Incoterms)', 'de' => 'Lieferbedingung (Incoterms)', 'fr' => 'Condition de livraison (Incoterms)'],
        'invoice' => ['it' => 'Fattura n.', 'en' => 'Invoice no.', 'de' => 'Rechnung Nr.', 'fr' => 'Facture n°'],
        'date' => ['it' => 'Data', 'en' => 'Date', 'de' => 'Datum', 'fr' => 'Date'],
        'description' => ['it' => 'Descrizione', 'en' => 'Description', 'de' => 'Beschreibung', 'fr' => 'Désignation'],
        'hs_code' => ['it' => 'Voce doganale (HS/TARIC)', 'en' => 'Tariff code (HS/TARIC)', 'de' => 'Zolltarifnummer (HS/TARIC)', 'fr' => 'Position tarifaire (SH/TARIC)'],
        'origin' => ['it' => 'Origine', 'en' => 'Origin', 'de' => 'Ursprung', 'fr' => 'Origine'],
        'packages' => ['it' => 'Colli', 'en' => 'Packages', 'de' => 'Packstücke', 'fr' => 'Colis'],
        'net' => ['it' => 'Peso netto kg', 'en' => 'Net kg', 'de' => 'Netto kg', 'fr' => 'Poids net kg'],
        'gross' => ['it' => 'Peso lordo kg', 'en' => 'Gross kg', 'de' => 'Brutto kg', 'fr' => 'Poids brut kg'],
        'value' => ['it' => 'Valore', 'en' => 'Value', 'de' => 'Wert', 'fr' => 'Valeur'],
        'total_value' => ['it' => 'Valore doganale totale', 'en' => 'Total customs value', 'de' => 'Gesamtzollwert', 'fr' => 'Valeur en douane totale'],
        'preferential_origin' => ['it' => 'Dichiarazione di origine preferenziale', 'en' => 'Preferential origin declaration', 'de' => 'Präferenzursprungserklärung', 'fr' => 'Déclaration d\'origine préférentielle'],
        'transit_title' => ['it' => 'Foglio di controllo transito', 'en' => 'Transit tracking sheet', 'de' => 'Versandkontrollblatt', 'fr' => 'Feuille de suivi du transit'],
        'transit_document' => ['it' => 'Documento di transito', 'en' => 'Transit document', 'de' => 'Versandpapier', 'fr' => 'Document de transit'],
        'guarantee' => ['it' => 'Riferimento garanzia', 'en' => 'Guarantee reference', 'de' => 'Sicherheitsreferenz', 'fr' => 'Référence de la garantie'],
        'departure_office' => ['it' => 'Ufficio di partenza', 'en' => 'Office of departure', 'de' => 'Abgangsstelle', 'fr' => 'Bureau de départ'],
        'destination_office' => ['it' => 'Ufficio di destinazione', 'en' => 'Office of destination', 'de' => 'Bestimmungsstelle', 'fr' => 'Bureau de destination'],
        'deadline' => ['it' => 'Termine di presentazione', 'en' => 'Time limit', 'de' => 'Frist', 'fr' => 'Délai de présentation'],
        'discharge' => ['it' => 'Stato di svincolo', 'en' => 'Discharge status', 'de' => 'Erledigungsstatus', 'fr' => 'Statut de décharge'],
        'discharged' => ['it' => 'Svincolato', 'en' => 'Discharged', 'de' => 'Erledigt', 'fr' => 'Déchargé'],
        'open' => ['it' => 'Aperto', 'en' => 'Open', 'de' => 'Offen', 'fr' => 'Ouvert'],
        'overdue' => ['it' => 'Scaduto', 'en' => 'Overdue', 'de' => 'Überfällig', 'fr' => 'Échu'],
        'border' => ['it' => 'Valico di frontiera', 'en' => 'Border crossing', 'de' => 'Grenzübergang', 'fr' => 'Frontière franchie'],
        'mrn' => ['it' => 'MRN', 'en' => 'MRN', 'de' => 'MRN', 'fr' => 'MRN'],
        'packing_title' => ['it' => 'Packing list / lista di colli', 'en' => 'Packing list', 'de' => 'Packliste', 'fr' => 'Liste de colisage'],
        'customs_value' => ['it' => 'Valore in dogana', 'en' => 'Customs value', 'de' => 'Zollwert', 'fr' => 'Valeur en douane'],
        'total' => ['it' => 'Totale', 'en' => 'Total', 'de' => 'Total', 'fr' => 'Total'],
        'place' => ['it' => 'Luogo', 'en' => 'Place', 'de' => 'Ort', 'fr' => 'Lieu'],
        'regime' => ['it' => 'Regime IVA', 'en' => 'VAT regime', 'de' => 'MWST-Regime', 'fr' => 'Régime TVA'],
    ];

    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly GenerateInvoiceOriginDeclarationAction $origin,
        private readonly CustomsValueCalculator $values,
        private readonly CrossBorderVatRegimeResolver $regimes,
    ) {}

    /**
     * @param  string  $pair  one of the keys of {@see self::LanguagePairs}
     *
     * @throws CustomsException when the language pair is unknown
     */
    public function renderProForma(CustomsDeclaration $declaration, Tenant $tenant, string $pair): string
    {
        $languages = self::LanguagePairs[$pair] ?? throw new CustomsException('The language pair must be it_en, it_de or it_fr.');
        $declaration->load(['items', 'consignor', 'consignee', 'importer', 'invoices']);
        $invoice = $declaration->invoices->first();
        $settings = CustomsSettings::query()->first();
        $declarationTexts = [];

        if ($invoice !== null) {
            foreach ($languages as $language) {
                $text = $this->origin->execute($invoice, $language)['declaration_text'];

                if ($text !== null) {
                    $declarationTexts[$language] = $text;
                }
            }
        }

        $party = $declaration->consignee ?? $declaration->importer;
        $incoterm = $declaration->incoterms ?? $invoice?->incoterm;
        $vatRegime = $invoice === null ? null : $this->regimeOf($invoice, $tenant);

        return $this->pdf->render('customs::pro-forma', [
            'incoterm' => $incoterm === null ? null : trim($incoterm.' '.($invoice->incoterm_place ?? '')),
            'vatRegime' => $vatRegime,
            'customsValue' => $this->customsValueOf($declaration, $invoice),
            'declaration' => $declaration,
            'tenant' => $tenant,
            'invoice' => $invoice,
            'languages' => $languages,
            'labels' => $this->labels($languages),
            'exporterIdentification' => $settings->trader_identification_number ?? $tenant->eori_number ?? $tenant->uid_number,
            'importerParty' => $party,
            'importerIdentification' => $party->eori_number ?? $party->uid_number ?? $party->vat_number,
            'currency' => $invoice->currency ?? $tenant->default_currency,
            'originTexts' => $declarationTexts,
        ]);
    }

    /**
     * Packing list of a declaration: packages, net and gross weight per line, the Incoterm and the MRN.
     *
     * @param  string  $pair  one of the keys of {@see self::LanguagePairs}
     *
     * @throws CustomsException when the language pair is unknown
     */
    public function renderPackingList(CustomsDeclaration $declaration, Tenant $tenant, string $pair): string
    {
        $languages = self::LanguagePairs[$pair] ?? throw new CustomsException('The language pair must be it_en, it_de or it_fr.');
        $declaration->load(['items', 'consignee', 'importer', 'invoices']);
        $invoice = $declaration->invoices->first();
        $settings = CustomsSettings::query()->first();
        $party = $declaration->consignee ?? $declaration->importer;
        $incoterm = $declaration->incoterms ?? $invoice?->incoterm;

        return $this->pdf->render('customs::packing-list', [
            'declaration' => $declaration,
            'tenant' => $tenant,
            'invoice' => $invoice,
            'languages' => $languages,
            'labels' => $this->labels($languages),
            'incoterm' => $incoterm === null ? null : trim($incoterm.' '.($invoice->incoterm_place ?? '')),
            'exporterIdentification' => $settings->trader_identification_number ?? $tenant->eori_number ?? $tenant->uid_number,
            'importerParty' => $party,
            'importerIdentification' => $party->eori_number ?? $party->uid_number ?? $party->vat_number,
        ]);
    }

    /**
     * The customs value of the declaration: the statistical total when stated, otherwise the invoice net total
     * adjusted for the carriage and insurance the Incoterm leaves outside the price.
     */
    private function customsValueOf(CustomsDeclaration $declaration, ?Invoice $invoice): ?string
    {
        if ((float) $declaration->total_statistical_value > 0) {
            return (string) $declaration->total_statistical_value;
        }

        $incoterm = Incoterm::tryFrom((string) ($declaration->incoterms ?? $invoice?->incoterm));
        if ($invoice === null || $incoterm === null) {
            return null;
        }

        return number_format($this->values->calculate($incoterm, (float) $invoice->total_net, (float) $invoice->freight_amount, (float) $invoice->insurance_amount)->customsValue, 2, '.', '');
    }

    private function regimeOf(Invoice $invoice, Tenant $tenant): string
    {
        $country = (string) Contact::withoutGlobalScopes()->whereKey($invoice->contact_id)->value('country');

        return $this->regimes->resolve((string) $tenant->country, $country)['regime'];
    }

    public function renderTransitSheet(CustomsDeclaration $declaration, Tenant $tenant, string $pair = 'it_en'): string
    {
        $languages = self::LanguagePairs[$pair] ?? throw new CustomsException('The language pair must be it_en, it_de or it_fr.');

        if (! $declaration->transit_document_type->isTransit()) {
            throw new CustomsException('The declaration has no T1 or T2 transit document.');
        }

        $status = match (true) {
            $declaration->transit_discharged_at !== null => 'discharged',
            $declaration->transit_deadline_on !== null && $declaration->transit_deadline_on->isPast() => 'overdue',
            default => 'open',
        };

        return $this->pdf->render('customs::transit-sheet', [
            'declaration' => $declaration,
            'tenant' => $tenant,
            'languages' => $languages,
            'labels' => $this->labels($languages),
            'dischargeStatus' => $status,
        ]);
    }

    /**
     * @param  array{0: string, 1: string}  $languages
     * @return array<string, array{0: string, 1: string}>
     */
    private function labels(array $languages): array
    {
        $labels = [];

        foreach (self::LABELS as $key => $translations) {
            $labels[$key] = [$translations[$languages[0]], $translations[$languages[1]]];
        }

        return $labels;
    }
}
