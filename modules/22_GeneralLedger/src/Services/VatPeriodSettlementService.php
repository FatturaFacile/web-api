<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Exceptions\VatSettlementException;
use Modules\GeneralLedger\Models\VatSettlement;
use Modules\GeneralLedger\Models\VatSettlementPeriod;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\ItalianVatPeriod;
use Modules\GeneralLedger\Services\Tax\LipeBoxes;
use Modules\GeneralLedger\Services\Tax\LipeData;
use Modules\GeneralLedger\Services\Tax\LipeXmlBuilder;
use Modules\GeneralLedger\Services\Tax\LipeXsdValidator;
use Modules\GeneralLedger\Services\Tax\SwissEstvXmlBuilder;
use Modules\GeneralLedger\Services\Tax\SwissRounding;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;
use Modules\GeneralLedger\Services\Tax\VatPeriod;
use Modules\GeneralLedger\Services\Tax\VatRegisterLine;
use Modules\GeneralLedger\Services\Tax\VatReturnResult;

/**
 * CH/IT VAT settlement engine on top of the ledger readers: previews a period (Swiss MWST Ziffern with 0.05 CHF rounding,
 * or Italian LIPE boxes VP2-VP14), locks it with a frozen calculation and exports the filing XML from that snapshot.
 */
final class VatPeriodSettlementService
{
    public const array BASES = ['agreed', 'received'];

    public function __construct(
        private readonly VatSettlementService $settlements,
        private readonly VatAccountResolver $accountResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(string $tenantId, VatPeriod $period): array
    {
        ['result' => $result, 'snapshot' => $snapshot, 'previous' => $previous] = $this->settlements->preview($tenantId, $period);
        $settings = $this->accountResolver->settingsOf($tenantId);
        $basis = (string) ($settings->mwst_basis ?? 'agreed');

        $calculation = [
            'jurisdiction' => $result->country,
            'period' => [
                'type' => $period->type,
                'year' => $period->year,
                'number' => $period->number,
                'label' => $period->label(),
                'from' => $result->periodFrom,
                'to' => $result->periodTo,
            ],
            'regime' => $result->regime,
            'basis' => $basis,
            'totals' => [
                'turnover' => $result->turnover,
                'taxable_turnover' => $result->taxableTurnover,
                'output_vat' => $result->outputVat,
                'input_vat' => $result->inputVat,
                'previous_credit' => $result->previousCredit,
                'previous_debit' => $result->previousDebit,
                'interest' => $result->interest,
            ],
            'warnings' => $snapshot->warnings,
            'drilldown' => [
                'sales' => array_map($this->line(...), $snapshot->saleLines),
                'purchases' => array_map($this->line(...), $snapshot->purchaseLines),
            ],
            'previous_settlement_id' => $previous?->id,
        ];

        if ($result->country === 'CH') {
            $boxes = $this->swissBoxes($result);
            $calculation['swiss_boxes'] = $boxes;
            $calculation['payable_amount'] = Decimal::isPositive($boxes['500']) ? $boxes['500'] : Decimal::neg($boxes['510']);
        } else {
            $lipe = $this->lipeBoxes($result, $snapshot->passiveOperations(), $period, $settings->lipe_prorata_percent ?? '100', $settings->lipe_advance_amount ?? '0');
            $calculation['lipe_boxes'] = $lipe->toArray();
            $calculation['lipe_meta'] = ['monthly' => $period->type === VatPeriod::MONTHLY];
            $calculation['payable_amount'] = Decimal::isPositive($lipe->vp14Payable) ? $lipe->vp14Payable : Decimal::neg($lipe->vp14Credit);
        }

        if ($basis === 'received') {
            $calculation['warnings'][] = 'Received (vereinnahmt) basis is stored with the period, but the amounts are still read from the ledger by posting date (agreed basis).';
        }

        $calculation['totals']['payable'] = $calculation['payable_amount'];

        return $calculation;
    }

    public function lock(string $tenantId, VatPeriod $period, ?string $actorUserId = null): VatSettlementPeriod
    {
        return DB::transaction(function () use ($tenantId, $period, $actorUserId): VatSettlementPeriod {
            $calculation = $this->preview($tenantId, $period);

            $existing = VatSettlementPeriod::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('jurisdiction', $calculation['jurisdiction'])
                ->whereDate('period_from', $period->from->toDateString())
                ->whereDate('period_to', $period->to->toDateString())
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw VatSettlementException::conflict("The VAT period {$period->label()} is already {$existing->status}.");
            }

            $calculation['frozen_at'] = now()->toIso8601String();

            return VatSettlementPeriod::create([
                'tenant_id' => $tenantId,
                'jurisdiction' => $calculation['jurisdiction'],
                'period_type' => $period->type,
                'period_from' => $period->from->toDateString(),
                'period_to' => $period->to->toDateString(),
                'regime' => $calculation['regime'],
                'basis' => $calculation['basis'],
                'calculation' => $calculation,
                'payable_amount' => $calculation['payable_amount'],
                'status' => VatSettlementPeriod::STATUS_CLOSED,
                'locked_at' => now(),
                'locked_by' => $actorUserId,
            ]);
        });
    }

    /**
     * Builds the filing XML from the frozen calculation: eCH-0217 for CH, LIPE (checked against the schema) for IT.
     *
     * @return array{file_name: string, content: string, content_type: string}
     */
    public function exportXml(VatSettlementPeriod $period): array
    {
        $calculation = $period->calculation;
        $tenant = Tenant::query()->findOrFail($period->tenant_id);

        if ($period->jurisdiction === 'CH') {
            $boxes = $calculation['swiss_boxes'] ?? [];
            $transient = new VatSettlement([
                'settlement_period' => $calculation['period']['label'],
                'regime' => $period->regime,
                'period_from' => $period->period_from->toDateString(),
                'period_to' => $period->period_to->toDateString(),
                'currency' => 'CHF',
                'estv_boxes' => $boxes,
                'output_vat_amount' => $boxes['399'] ?? '0.00',
            ]);

            return [
                'file_name' => SwissEstvXmlBuilder::fileName($transient),
                'content' => SwissEstvXmlBuilder::build($transient, $tenant),
                'content_type' => 'application/xml',
            ];
        }

        $vatNumber = preg_replace('/[^A-Za-z0-9]/', '', (string) $tenant->vat_number) ?? '';
        $vatNumber = str_starts_with(strtoupper($vatNumber), 'IT') ? substr($vatNumber, 2) : $vatNumber;

        if (strlen($vatNumber) !== 11) {
            throw VatSettlementException::invalid('The Italian VAT number (11 digits) of the company is missing.');
        }

        $taxCode = strtoupper(trim((string) $tenant->fiscal_code) === '' ? $vatNumber : preg_replace('/[^A-Za-z0-9]/', '', (string) $tenant->fiscal_code));
        $settings = $this->accountResolver->settingsOf((string) $period->tenant_id);
        $declarant = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $settings->declarant_tax_code) ?? '');
        $monthly = ItalianVatPeriod::isMonthly($period->period_from, $period->period_to);
        $vp = $calculation['lipe_boxes'];

        $data = new LipeData(
            taxCode: $taxCode,
            vatNumber: $vatNumber,
            year: (int) $period->period_from->format('Y'),
            month: $monthly ? (int) $period->period_from->format('n') : null,
            quarter: $monthly ? null : ItalianVatPeriod::quarter($period->period_from),
            declarantTaxCode: $declarant === '' || $declarant === $taxCode ? null : $declarant,
            declarantRoleCode: $settings->declarant_role_code ?: '1',
            activeOperations: $vp['VP2'],
            passiveOperations: $vp['VP3'],
            outputVat: $vp['VP4'],
            inputVat: $vp['VP5'],
            previousDebit: $vp['VP7'],
            previousCredit: $vp['VP8'],
            interest: $vp['VP12'],
            payable: Decimal::isPositive($vp['VP14_payable']) ? $vp['VP14_payable'] : Decimal::neg($vp['VP14_credit']),
            previousYearCredit: $vp['VP9'],
            euAutoPayments: $vp['VP10'],
            taxCredits: $vp['VP11'],
            advance: $vp['VP13'],
        );

        $xml = LipeXmlBuilder::build($data);
        LipeXsdValidator::assertValid($xml);

        return ['file_name' => LipeXmlBuilder::fileName($data, $data->month ?? $data->quarter ?? 1), 'content' => $xml, 'content_type' => 'application/xml'];
    }

    /**
     * Swiss boxes with the VAT amounts rounded to 0.05 CHF.
     *
     * @return array<int|string, mixed>
     */
    private function swissBoxes(VatReturnResult $result): array
    {
        $boxes = $result->estvBoxes;

        foreach (['303', '313', '343', '383'] as $code) {
            $box = $boxes[$code] ?? null;

            if (is_array($box) && isset($box['tax'])) {
                $box['tax'] = SwissRounding::to05((string) $box['tax']);
                $boxes[$code] = $box;
            }
        }

        foreach (['399', '479'] as $code) {
            $boxes[$code] = SwissRounding::to05((string) ($boxes[$code] ?? '0'));
        }

        $net = Decimal::sub($boxes['399'], $boxes['479']);
        $boxes['500'] = Decimal::isPositive($net) ? Decimal::round2($net) : '0.00';
        $boxes['510'] = Decimal::isNegative($net) ? Decimal::round2(Decimal::neg($net)) : '0.00';

        return $boxes;
    }

    private function lipeBoxes(VatReturnResult $result, string $passive, VatPeriod $period, mixed $proRata, mixed $advance): LipeBoxes
    {
        $previousCredit = $result->previousCredit;
        $firstOfYear = $period->from->month === 1 && $period->from->day === 1;
        $isLastOfYear = $period->to->month === 12;

        return LipeBoxes::compute(
            activeOperations: $result->turnover,
            passiveOperations: $passive,
            outputVat: $result->outputVat,
            inputVat: $result->inputVat,
            previousDebit: $result->previousDebit,
            previousCredit: $previousCredit,
            firstPeriodOfYear: $firstOfYear,
            quarterly: $period->type === VatPeriod::QUARTERLY,
            proRataPercent: (string) $proRata,
            advance: $isLastOfYear ? (string) $advance : '0',
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function line(VatRegisterLine $line): array
    {
        return [
            'journal_entry_id' => $line->journalEntryId,
            'entry_number' => $line->entryNumber,
            'posting_date' => $line->postingDate,
            'document_number' => $line->documentNumber,
            'source_document_type' => $line->sourceDocumentType,
            'partner_id' => $line->partnerId,
            'taxable' => $line->taxable,
            'rate' => $line->rate,
            'tax' => $line->tax,
            'treatment' => $line->treatment,
        ];
    }
}
