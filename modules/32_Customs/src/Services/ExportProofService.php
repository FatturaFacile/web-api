<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use App\Models\Invoice;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Enums\DeclarationStatus;
use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsDeclaration;

/**
 * Ties export declarations to the invoices they clear and tells which of them still lack the proof of export.
 *
 * The proof of a zero-rated export is the Swiss export assessment decision (eVV). A decision already points at its
 * declaration (by MRN or trader declaration number, when it is archived) and is immutable, so the match to an invoice
 * is kept on the declaration side: an invoice has its proof when a declaration linked to it has an export decision.
 */
final class ExportProofService
{
    /**
     * @param  list<string>  $invoiceIds
     *
     * @throws CustomsException when an invoice does not belong to the company
     */
    public function link(CustomsDeclaration $declaration, array $invoiceIds): CustomsDeclaration
    {
        $invoiceIds = array_values(array_unique($invoiceIds));
        $found = Invoice::query()->whereIn('id', $invoiceIds)->pluck('id')->all();

        if (count($found) !== count($invoiceIds)) {
            throw new CustomsException('An invoice does not exist.');
        }

        $declaration->invoices()->syncWithoutDetaching(array_fill_keys($found, ['tenant_id' => $declaration->tenant_id]));

        return $declaration->load('invoices');
    }

    /**
     * Links the invoices whose number is the trader reference or the trader declaration number of the declaration.
     *
     * @return int the invoices linked now
     */
    public function autoLink(CustomsDeclaration $declaration): int
    {
        $references = array_values(array_filter([$declaration->trader_reference, $declaration->trader_declaration_number]));

        if ($references === []) {
            return 0;
        }

        $ids = Invoice::query()->whereIn('invoice_number', $references)->pluck('id')->all();
        $already = $declaration->invoices()->pluck('invoices.id')->all();
        $new = array_values(array_diff($ids, $already));

        if ($new !== []) {
            $declaration->invoices()->attach(array_fill_keys($new, ['tenant_id' => $declaration->tenant_id]));
        }

        return count($new);
    }

    /**
     * The latest export decision of the declaration.
     */
    public function decisionFor(CustomsDeclaration $declaration): ?CustomsAssessmentDecision
    {
        return CustomsAssessmentDecision::query()
            ->where('declaration_id', $declaration->id)
            ->where('document_type', AssessmentDocumentType::Export->value)
            ->orderByDesc('version')
            ->first();
    }

    public function hasProof(Invoice $invoice): bool
    {
        return CustomsDeclaration::query()
            ->whereHas('invoices', static fn ($query) => $query->whereKey($invoice->id))
            ->get()
            ->contains(fn (CustomsDeclaration $declaration): bool => $this->decisionFor($declaration) !== null);
    }

    /**
     * Accepted export declarations whose export decision has not arrived.
     *
     * @return list<array{declaration_id: string, trader_declaration_number: string, mrn: string|null, invoices: list<array{id: string, invoice_number: string}>}>
     */
    public function missingProof(): array
    {
        $withProof = CustomsAssessmentDecision::query()
            ->where('document_type', AssessmentDocumentType::Export->value)
            ->whereNotNull('declaration_id')
            ->pluck('declaration_id')
            ->all();

        return CustomsDeclaration::query()
            ->with('invoices:id,invoice_number')
            ->where('declaration_type', DeclarationType::Export->value)
            ->where('status', DeclarationStatus::Accepted->value)
            ->whereNotIn('id', $withProof)
            ->orderBy('accepted_at')
            ->get()
            ->map(static fn (CustomsDeclaration $declaration): array => [
                'declaration_id' => (string) $declaration->id,
                'trader_declaration_number' => (string) $declaration->trader_declaration_number,
                'mrn' => $declaration->mrn,
                'invoices' => $declaration->invoices->map(static fn (Invoice $invoice): array => ['id' => (string) $invoice->id, 'invoice_number' => (string) $invoice->invoice_number])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * The numbers of the dashboard: declarations by direction, regime and status, the transit that ran out of time and
     * the exports still without proof.
     *
     * @return array{by_direction: array<string, int>, by_regime: array<string, int>, by_status: array<string, int>, transit_overdue: int, missing_evv: list<array<string, mixed>>}
     */
    public function dashboard(): array
    {
        $count = static fn (string $column): array => CustomsDeclaration::query()
            ->selectRaw("{$column} as bucket, count(*) as total")
            ->groupBy($column)
            ->pluck('total', 'bucket')
            ->map(static fn ($total): int => (int) $total)
            ->all();

        return [
            'by_direction' => $count('declaration_type'),
            'by_regime' => $count('customs_regime'),
            'by_status' => $count('status'),
            'transit_overdue' => CustomsDeclaration::query()
                ->where('transit_document_type', '!=', 'none')
                ->whereNull('transit_discharged_at')
                ->whereDate('transit_deadline_on', '<', now()->toDateString())
                ->count(),
            'missing_evv' => $this->missingProof(),
        ];
    }
}
