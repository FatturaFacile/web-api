<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use Illuminate\Support\Collection;
use Modules\Customs\Enums\LedgerPostingStatus;
use Modules\Customs\Enums\SignatureStatus;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsRetrievalRun;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The daily control list and the statistics of the assessment decisions.
 */
final class AssessmentReportingService
{
    public const string FLAG_SIGNATURE_INVALID = 'signature_invalid';

    public const string FLAG_SIGNATURE_UNCHECKED = 'signature_unchecked';

    public const string FLAG_FILE_ALTERED = 'file_altered';

    public const string FLAG_NO_DECLARATION = 'no_declaration';

    public const string FLAG_NOT_POSTED = 'not_posted';

    public const string FLAG_VAT_VALUE_DIFFERS = 'vat_value_differs';

    public function __construct(private readonly AssessmentArchive $archive) {}

    /**
     * What arrived on a day, each decision with its error flags, and the retrieval runs of that day.
     *
     * @return array{date: string, summary: array<string, int|string>, decisions: list<array<string, mixed>>, runs: Collection<int, CustomsRetrievalRun>}
     */
    public function controlList(string $date): array
    {
        $decisions = CustomsAssessmentDecision::query()
            ->with('ledgerPosting')
            ->whereDate('retrieved_at', $date)
            ->orderBy('document_type')
            ->orderBy('mrn')
            ->get();

        $declarations = CustomsDeclaration::query()->whereIn('id', $decisions->pluck('declaration_id')->filter())->get()->keyBy('id');

        $rows = $decisions->map(function (CustomsAssessmentDecision $decision) use ($declarations): array {
            $declaration = $declarations->get($decision->declaration_id);

            return [
                'id' => $decision->id,
                'mrn' => $decision->mrn,
                'version' => $decision->version,
                'document_type' => $decision->document_type->value,
                'direction' => $decision->direction,
                'duty_amount' => $decision->duty_amount,
                'vat_amount' => $decision->vat_amount,
                'flags' => $this->flagsOf($decision, $declaration),
            ];
        })->values()->all();

        return [
            'date' => $date,
            'summary' => [
                'decisions' => count($rows),
                'with_flags' => count(array_filter($rows, static fn (array $row): bool => $row['flags'] !== [])),
                'duty_total' => Decimal::round2($decisions->reduce(static fn (string $total, CustomsAssessmentDecision $item): string => Decimal::add($total, (string) $item->duty_amount), '0')),
                'vat_total' => Decimal::round2($decisions->reduce(static fn (string $total, CustomsAssessmentDecision $item): string => Decimal::add($total, (string) $item->vat_amount), '0')),
            ],
            'decisions' => $rows,
            'runs' => CustomsRetrievalRun::query()->whereDate('started_at', $date)->latest('started_at')->get(),
        ];
    }

    /**
     * Totals by month, direction and document type.
     *
     * @return list<array{month: string, direction: string, document_type: string, decisions: int, duty_total: string, vat_total: string}>
     */
    public function statistics(string $from, string $to): array
    {
        return CustomsAssessmentDecision::query()
            ->whereBetween('document_date', [$from, $to])
            ->selectRaw("to_char(document_date, 'YYYY-MM') as month, direction, document_type, count(*) as decisions, sum(duty_amount) as duty_total, sum(vat_amount) as vat_total")
            ->groupByRaw("to_char(document_date, 'YYYY-MM'), direction, document_type")
            ->orderBy('month')
            ->orderBy('direction')
            ->orderBy('document_type')
            ->toBase()
            ->get()
            ->map(static fn (object $row): array => [
                'month' => (string) $row->month,
                'direction' => (string) $row->direction,
                'document_type' => (string) $row->document_type,
                'decisions' => (int) $row->decisions,
                'duty_total' => Decimal::round2((string) $row->duty_total),
                'vat_total' => Decimal::round2((string) $row->vat_total),
            ])
            ->all();
    }

    /**
     * @return list<string>
     */
    private function flagsOf(CustomsAssessmentDecision $decision, ?CustomsDeclaration $declaration): array
    {
        $flags = [];

        if ($decision->signature_status === SignatureStatus::Invalid) {
            $flags[] = self::FLAG_SIGNATURE_INVALID;
        } elseif ($decision->signature_status !== SignatureStatus::Verified) {
            $flags[] = self::FLAG_SIGNATURE_UNCHECKED;
        }

        if (! $this->archive->verifyIntegrity($decision)['is_intact']) {
            $flags[] = self::FLAG_FILE_ALTERED;
        }

        if ($declaration === null) {
            $flags[] = self::FLAG_NO_DECLARATION;
        }

        if ($decision->direction === 'import' && $decision->ledgerPosting?->status !== LedgerPostingStatus::Posted) {
            $flags[] = self::FLAG_NOT_POSTED;
        }

        return $flags;
    }
}
