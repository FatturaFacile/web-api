<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use Illuminate\Support\Facades\DB;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsSettings;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Compares what customs assessed with what the customs payable account holds. Each document counts once, at its latest
 * version, and the ledger side is read from the journal lines, not from the postings of this module.
 */
final class CustomsReconciliationService
{
    /**
     * @return array{from: string, to: string, assessed_total: string, ledger_total: string, difference: string, lines: list<array<string, mixed>>}
     */
    public function forPeriod(string $tenantId, string $from, string $to): array
    {
        $groups = CustomsAssessmentDecision::query()
            ->where('direction', 'import')
            ->whereBetween('document_date', [$from, $to])
            ->orderBy('mrn')
            ->orderBy('version')
            ->get()
            ->groupBy(static fn (CustomsAssessmentDecision $decision): string => $decision->mrn.'|'.$decision->document_type->value);

        $payableId = CustomsSettings::query()->value('customs_payable_account_id');
        $lines = [];
        $assessedTotal = '0';
        $ledgerTotal = '0';

        foreach ($groups as $versions) {
            /** @var CustomsAssessmentDecision $latest */
            $latest = $versions->last();
            $assessed = $this->signedAmount($latest);
            $ledger = $this->ledgerAmount($tenantId, $versions->pluck('id')->all(), $payableId);
            $difference = Decimal::sub($assessed, $ledger);

            $assessedTotal = Decimal::add($assessedTotal, $assessed);
            $ledgerTotal = Decimal::add($ledgerTotal, $ledger);

            $lines[] = [
                'mrn' => $latest->mrn,
                'document_type' => $latest->document_type->value,
                'version' => $latest->version,
                'assessed' => Decimal::round2($assessed),
                'ledger' => Decimal::round2($ledger),
                'difference' => Decimal::round2($difference),
                'matches' => Decimal::isZero($difference),
            ];
        }

        return [
            'from' => $from,
            'to' => $to,
            'assessed_total' => Decimal::round2($assessedTotal),
            'ledger_total' => Decimal::round2($ledgerTotal),
            'difference' => Decimal::round2(Decimal::sub($assessedTotal, $ledgerTotal)),
            'lines' => $lines,
        ];
    }

    private function signedAmount(CustomsAssessmentDecision $decision): string
    {
        $amount = in_array($decision->document_type, [AssessmentDocumentType::Vat, AssessmentDocumentType::RefundVat], true)
            ? (string) $decision->vat_amount
            : (string) $decision->duty_amount;

        if ($decision->amount_not_charged) {
            return '0';
        }

        return Decimal::round2($decision->document_type->isRefund() ? Decimal::neg($amount) : $amount);
    }

    /**
     * @param  list<string>  $decisionIds
     */
    private function ledgerAmount(string $tenantId, array $decisionIds, ?string $payableId): string
    {
        if ($payableId === null) {
            return '0';
        }

        $row = DB::table('journal_lines as jl')
            ->join('journal_entries as je', 'jl.journal_entry_id', '=', 'je.id')
            ->where('je.tenant_id', $tenantId)
            ->whereNull('je.deleted_at')
            ->whereNull('jl.deleted_at')
            ->where('je.status', 'posted')
            ->where('je.source_document_type', CustomsLedgerService::SOURCE)
            ->whereIn('je.source_document_id', $decisionIds)
            ->where('jl.account_id', $payableId)
            ->selectRaw('coalesce(sum(jl.credit_amount), 0) as credits, coalesce(sum(jl.debit_amount), 0) as debits')
            ->first();

        return Decimal::sub((string) $row->credits, (string) $row->debits);
    }
}
