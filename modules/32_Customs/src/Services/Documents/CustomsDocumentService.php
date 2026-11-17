<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Documents;

use App\Actions\Customs\GenerateInvoiceOriginDeclarationAction;
use App\Contracts\PdfGenerator;
use App\Models\Tenant;
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
    public const array LanguagePairs = ['it_en' => ['it', 'en'], 'it_de' => ['it', 'de']];

    /**
     * @var array<string, array{it: string, en: string, de: string}>
     */
    private const array LABELS = [
        'title' => ['it' => 'Fattura commerciale / pro-forma per la dogana', 'en' => 'Commercial / pro-forma invoice for customs', 'de' => 'Handels- / Proforma-Rechnung für den Zoll'],
        'exporter' => ['it' => 'Esportatore', 'en' => 'Exporter', 'de' => 'Exporteur'],
        'importer' => ['it' => 'Importatore / destinatario', 'en' => 'Importer / consignee', 'de' => 'Importeur / Empfänger'],
        'identification' => ['it' => 'EORI / UID', 'en' => 'EORI / UID', 'de' => 'EORI / UID'],
        'incoterms' => ['it' => 'Resa (Incoterms)', 'en' => 'Terms of delivery (Incoterms)', 'de' => 'Lieferbedingung (Incoterms)'],
        'invoice' => ['it' => 'Fattura n.', 'en' => 'Invoice no.', 'de' => 'Rechnung Nr.'],
        'date' => ['it' => 'Data', 'en' => 'Date', 'de' => 'Datum'],
        'description' => ['it' => 'Descrizione', 'en' => 'Description', 'de' => 'Beschreibung'],
        'hs_code' => ['it' => 'Voce doganale (HS/TARIC)', 'en' => 'Tariff code (HS/TARIC)', 'de' => 'Zolltarifnummer (HS/TARIC)'],
        'origin' => ['it' => 'Origine', 'en' => 'Origin', 'de' => 'Ursprung'],
        'packages' => ['it' => 'Colli', 'en' => 'Packages', 'de' => 'Packstücke'],
        'net' => ['it' => 'Peso netto kg', 'en' => 'Net kg', 'de' => 'Netto kg'],
        'gross' => ['it' => 'Peso lordo kg', 'en' => 'Gross kg', 'de' => 'Brutto kg'],
        'value' => ['it' => 'Valore', 'en' => 'Value', 'de' => 'Wert'],
        'total_value' => ['it' => 'Valore doganale totale', 'en' => 'Total customs value', 'de' => 'Gesamtzollwert'],
        'preferential_origin' => ['it' => 'Dichiarazione di origine preferenziale', 'en' => 'Preferential origin declaration', 'de' => 'Präferenzursprungserklärung'],
        'transit_title' => ['it' => 'Foglio di controllo transito', 'en' => 'Transit tracking sheet', 'de' => 'Versandkontrollblatt'],
        'transit_document' => ['it' => 'Documento di transito', 'en' => 'Transit document', 'de' => 'Versandpapier'],
        'guarantee' => ['it' => 'Riferimento garanzia', 'en' => 'Guarantee reference', 'de' => 'Sicherheitsreferenz'],
        'departure_office' => ['it' => 'Ufficio di partenza', 'en' => 'Office of departure', 'de' => 'Abgangsstelle'],
        'destination_office' => ['it' => 'Ufficio di destinazione', 'en' => 'Office of destination', 'de' => 'Bestimmungsstelle'],
        'deadline' => ['it' => 'Termine di presentazione', 'en' => 'Time limit', 'de' => 'Frist'],
        'discharge' => ['it' => 'Stato di svincolo', 'en' => 'Discharge status', 'de' => 'Erledigungsstatus'],
        'discharged' => ['it' => 'Svincolato', 'en' => 'Discharged', 'de' => 'Erledigt'],
        'open' => ['it' => 'Aperto', 'en' => 'Open', 'de' => 'Offen'],
        'overdue' => ['it' => 'Scaduto', 'en' => 'Overdue', 'de' => 'Überfällig'],
        'border' => ['it' => 'Valico di frontiera', 'en' => 'Border crossing', 'de' => 'Grenzübergang'],
        'mrn' => ['it' => 'MRN', 'en' => 'MRN', 'de' => 'MRN'],
    ];

    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly GenerateInvoiceOriginDeclarationAction $origin,
    ) {}

    /**
     * @param  string  $pair  one of the keys of {@see self::LanguagePairs}
     *
     * @throws CustomsException when the language pair is unknown
     */
    public function renderProForma(CustomsDeclaration $declaration, Tenant $tenant, string $pair): string
    {
        $languages = self::LanguagePairs[$pair] ?? throw new CustomsException('The language pair must be it_en or it_de.');
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

        return $this->pdf->render('customs::pro-forma', [
            'declaration' => $declaration,
            'tenant' => $tenant,
            'invoice' => $invoice,
            'languages' => $languages,
            'labels' => $this->labels($languages),
            'exporterIdentification' => $settings->trader_identification_number ?? $tenant->uid_number,
            'importerParty' => $party,
            'importerIdentification' => $party->uid_number ?? $party->vat_number,
            'currency' => $invoice->currency ?? $tenant->default_currency,
            'originTexts' => $declarationTexts,
        ]);
    }

    public function renderTransitSheet(CustomsDeclaration $declaration, Tenant $tenant, string $pair = 'it_en'): string
    {
        $languages = self::LanguagePairs[$pair] ?? throw new CustomsException('The language pair must be it_en or it_de.');

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
