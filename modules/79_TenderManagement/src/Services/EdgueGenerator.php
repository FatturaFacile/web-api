<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use DOMDocument;
use Modules\TenderManagement\Domain\Enums\VaultDocType;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderParticipation;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

/**
 * Builds the eDGUE (European Single Procurement Document, ESPD-EDM) of an Italian public tender from the company master data,
 * the tender notice and the qualification vault, as structured data, as ESPD style XML and as a PDF.
 *
 * The exclusion grounds (Art. 94-98 D.Lgs. 36/2023) are generated as "no ground applies" declarations that the legal
 * representative must confirm and sign: nothing is declared on the company's behalf without that confirmation.
 */
final class EdgueGenerator
{
    private const string Version = '3.3.0';

    /** @var list<string> */
    private const array ExclusionGrounds = ['criminal_convictions', 'corruption', 'fraud', 'terrorist_offences', 'money_laundering', 'child_labour', 'tax_payment', 'social_security_payment', 'labour_law_breach', 'insolvency', 'professional_misconduct', 'conflict_of_interest'];

    public function __construct(
        private readonly TenderStrategyRegistry $strategies,
        private readonly VaultService $vault,
        private readonly VaultStatusResolver $status,
    ) {}

    /**
     * @return array{format: string, version: string, xml: string, data: array<string, mixed>}
     */
    public function generate(TenderParticipation $participation, CarbonImmutable $today): array
    {
        $notice = $participation->notice;

        if (! $this->strategies->for($notice->jurisdiction)->supportsEdgue()) {
            throw new TenderException('edgue_unsupported_jurisdiction');
        }

        $tenant = Tenant::query()->find(app(TenantContext::class)->getTenantId());
        $documents = $this->vault->activeDocuments()->filter(fn (QualificationVaultDocument $document): bool => $this->status->isUsable($document, $today));
        $casellario = $documents->first(fn (QualificationVaultDocument $document): bool => $document->doc_type === VaultDocType::ItCasellario);

        $data = [
            'procedure' => [
                'contracting_authority' => $notice->contracting_authority_name,
                'title' => $notice->title,
                'cig' => $notice->cig_code_it,
                'cup' => $notice->cup_code_it,
                'rup' => $notice->rup_name,
                'cpv' => $notice->cpv_category_codes ?? [],
            ],
            'economic_operator' => [
                'name' => $tenant?->legal_name,
                'vat_number' => $tenant?->vat_number,
                'fiscal_code' => $tenant?->fiscal_code,
                'country' => $tenant?->country,
                'pec' => $tenant?->pec_email,
                'participation_mode' => $participation->participation_mode->value,
                'partners' => $participation->partner_companies ?? [],
            ],
            'exclusion_grounds' => array_map(static fn (string $ground): array => [
                'ground' => $ground,
                'applies' => false,
                'requires_operator_confirmation' => true,
                'evidence_document' => $ground === 'criminal_convictions' ? $casellario?->document_number : null,
            ], self::ExclusionGrounds),
            'selection' => [
                'soa' => $documents->filter(fn (QualificationVaultDocument $document): bool => $document->doc_type === VaultDocType::ItSoa)
                    ->flatMap(fn (QualificationVaultDocument $document): array => array_map(static fn (array $row): array => $row + ['attestation' => $document->document_number, 'valid_until' => $document->expiry_date?->toDateString()], $document->soa_categories_classifications ?? []))
                    ->values()->all(),
                'certifications' => $documents->filter(fn (QualificationVaultDocument $document): bool => in_array($document->doc_type, [VaultDocType::ItIso9001, VaultDocType::ItIso14001, VaultDocType::ItIso45001, VaultDocType::ItEmas, VaultDocType::ItPdr125], true))
                    ->map(fn (QualificationVaultDocument $document): array => ['type' => $document->doc_type->value, 'number' => $document->document_number, 'valid_until' => $document->expiry_date?->toDateString()])->values()->all(),
                'required_min_turnover' => $notice->required_min_turnover,
            ],
            'generated_at' => $today->toIso8601String(),
        ];

        $document = ['format' => 'edgue', 'version' => self::Version, 'xml' => $this->toXml($data), 'data' => $data];
        $participation->forceFill(['edgue_data' => $document['data'] + ['version' => self::Version, 'xml' => $document['xml']]])->save();

        return $document;
    }

    public function pdf(TenderParticipation $participation, CarbonImmutable $today): string
    {
        $document = $participation->edgue_data !== null ? ['data' => $participation->edgue_data] : $this->generate($participation, $today);
        $html = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:10px}h2{font-size:12px}td{border:1px solid #999;padding:3px}table{border-collapse:collapse;width:100%}</style></head><body>'
            .'<h1>eDGUE / ESPD-EDM '.self::Version.'</h1>'.$this->renderHtml($document['data']).'</body></html>';

        return Pdf::loadHTML($html)->setPaper('a4')->output();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toXml(array $data): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS('urn:oasis:names:specification:ubl:schema:xsd:QualificationApplicationResponse-2', 'espd:ESPDResponse');
        $root->setAttribute('version', self::Version);
        $dom->appendChild($root);
        $this->append($dom, $root, $data);

        return (string) $dom->saveXML();
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function append(DOMDocument $dom, \DOMElement $parent, array $data): void
    {
        foreach ($data as $key => $value) {
            $name = is_int($key) ? 'item' : preg_replace('/[^A-Za-z0-9_]/', '_', (string) $key);
            $element = $dom->createElement((string) $name);
            $parent->appendChild($element);

            if (is_array($value)) {
                $this->append($dom, $element, $value);
            } elseif (is_bool($value)) {
                $element->appendChild($dom->createTextNode($value ? 'true' : 'false'));
            } elseif ($value !== null) {
                $element->appendChild($dom->createTextNode((string) $value));
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function renderHtml(array $data): string
    {
        $rows = '';

        foreach ($data as $key => $value) {
            $label = e((string) $key);
            $cell = is_array($value) ? $this->renderHtml($value) : e(is_bool($value) ? ($value ? 'true' : 'false') : (string) ($value ?? ''));
            $rows .= "<tr><td width=\"30%\"><b>{$label}</b></td><td>{$cell}</td></tr>";
        }

        return "<table>{$rows}</table>";
    }
}
