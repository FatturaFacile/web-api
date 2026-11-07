<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Enums\LedgerPostingStatus;
use Modules\Customs\Enums\SignatureStatus;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsAssessmentDecision;
use Modules\Customs\Models\CustomsLedgerPosting;
use Modules\Customs\Models\CustomsSettings;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;
use Modules\GeneralLedger\Services\Tax\VatAccountResolver;

/**
 * Posts duty and import VAT of an assessment decision through the double-entry service of the General Ledger:
 *
 *   duty decision     Dr duty expense          Cr customs payable
 *   VAT decision      Dr input VAT (per rate)  Cr customs payable
 *   refund decisions  the same, reversed
 *
 * The VAT lines carry base and rate and hit an input VAT account of the tenant, which is what VatLedgerReader reads, so
 * import VAT reaches the Swiss VAT return without further work. No account is assumed: a missing mapping refuses the
 * posting and leaves the reason on it. A corrected version posts only the difference from the version already posted.
 */
final class CustomsLedgerService
{
    public const string SOURCE = 'customs_assessment';

    private const string CURRENCY = 'CHF';

    /** A refund of VAT holds no goods items, so it states no rate. */
    private const string UNRATED = 'unrated';

    public function __construct(
        private readonly DoubleEntryPostingServiceInterface $postingService,
        private readonly FiscalPeriodResolver $periods,
        private readonly VatAccountResolver $vatAccounts,
        private readonly AssessmentDecisionParser $parser,
        private readonly AssessmentArchive $archive,
    ) {}

    public function post(CustomsAssessmentDecision $decision, ?string $actorId): CustomsLedgerPosting
    {
        $posting = CustomsLedgerPosting::query()->firstOrNew(['assessment_decision_id' => $decision->id]);

        if ($posting->status === LedgerPostingStatus::Posted) {
            return $posting;
        }

        if ($decision->document_type === AssessmentDocumentType::Export) {
            throw new CustomsException('An export decision raises no duty or import VAT to post.');
        }

        if ($decision->signature_status === SignatureStatus::Invalid) {
            throw new CustomsException('A decision whose signature does not verify cannot be posted.');
        }

        if (! $this->archive->verifyIntegrity($decision)['is_intact']) {
            throw new CustomsException('The archived file of the decision does not match its hash.');
        }

        $posting->id ??= (string) Str::uuid7();
        $posting->tenant_id = $decision->tenant_id;
        $posting->fill([
            'status' => LedgerPostingStatus::Pending,
            'posting_date' => $decision->document_date,
            'duty_amount' => Decimal::round2((string) $decision->duty_amount),
            'vat_amount' => Decimal::round2((string) $decision->vat_amount),
            'failure_reason' => null,
        ]);

        if ($decision->amount_not_charged) {
            $posting->fill(['status' => LedgerPostingStatus::NotPostable, 'failure_reason' => 'The decision states that the amount is not charged.'])->save();

            return $posting;
        }

        try {
            DB::transaction(function () use ($decision, $posting, $actorId): void {
                $posting->journal_entry_id = $this->writeEntry($decision, $actorId);
                $posting->status = LedgerPostingStatus::Posted;
                $posting->save();
            });
        } catch (InvalidArgumentException $e) {
            $posting->failure_reason = $e->getMessage();
            $posting->save();

            throw new CustomsException($e->getMessage());
        }

        return $posting;
    }

    /**
     * @return string|null the journal entry, none when a corrected version does not change the amounts
     */
    private function writeEntry(CustomsAssessmentDecision $decision, ?string $actorId): ?string
    {
        $tenantId = (string) $decision->tenant_id;

        if (TenantAccountingCurrency::of($tenantId) !== self::CURRENCY) {
            throw new InvalidArgumentException('Swiss assessment decisions are in CHF: the accounting currency of the company is not.');
        }

        $settings = CustomsSettings::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        $payableId = $this->ownAccount($tenantId, $settings?->customs_payable_account_id, 'customs payable');
        $previous = $this->previousPosted($decision);
        $sign = $decision->document_type->isRefund() ? '-1' : '1';

        $lines = in_array($decision->document_type, [AssessmentDocumentType::Vat, AssessmentDocumentType::RefundVat], true)
            ? $this->vatLines($decision, $previous, $sign, $this->inputVatAccount($tenantId, $settings), $payableId)
            : $this->dutyLines($decision, $previous, $sign, $this->ownAccount($tenantId, $settings?->duty_expense_account_id, 'duty expense'), $payableId);

        if ($lines === []) {
            return null;
        }

        $entry = $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'fiscal_period_id' => $this->periods->forDate($tenantId, $decision->document_date)->id,
            'posting_date' => $decision->document_date->toDateString(),
            'document_date' => $decision->document_date->toDateString(),
            'description' => "Customs assessment {$decision->mrn} v{$decision->version}",
            'source_document_type' => self::SOURCE,
            'source_document_id' => $decision->id,
            'document_number' => $decision->mrn,
            'lines' => $lines,
        ], $actorId);

        return (string) $entry->id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dutyLines(CustomsAssessmentDecision $decision, ?CustomsAssessmentDecision $previous, string $sign, string $expenseId, string $payableId): array
    {
        $amount = Decimal::mul(Decimal::round2((string) $decision->duty_amount), $sign);

        if ($previous !== null) {
            $amount = Decimal::sub($amount, Decimal::mul(Decimal::round2((string) $previous->duty_amount), $sign));
        }

        return Decimal::isZero($amount) ? [] : [...$this->pair($expenseId, $amount), ...$this->pair($payableId, Decimal::neg($amount))];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vatLines(CustomsAssessmentDecision $decision, ?CustomsAssessmentDecision $previous, string $sign, string $inputVatId, string $payableId): array
    {
        $groups = $this->vatGroups($decision, $sign);

        foreach ($previous === null ? [] : $this->vatGroups($previous, $sign) as $rate => $group) {
            $groups[$rate] = [
                'base' => Decimal::sub($groups[$rate]['base'] ?? '0', $group['base']),
                'amount' => Decimal::sub($groups[$rate]['amount'] ?? '0', $group['amount']),
            ];
        }

        $lines = [];
        $total = '0';

        foreach ($groups as $rate => $group) {
            if (Decimal::isZero($group['amount'])) {
                continue;
            }

            $total = Decimal::add($total, $group['amount']);
            $lines = [...$lines, ...$this->pair($inputVatId, $group['amount'], [
                'vat_base_amount' => Decimal::round2(Decimal::abs($group['base'])),
                'vat_tax_amount' => Decimal::round2(Decimal::abs($group['amount'])),
                'vat_rate_percent' => $rate === self::UNRATED ? null : (string) $rate,
            ])];
        }

        return $lines === [] ? [] : [...$lines, ...$this->pair($payableId, Decimal::neg($total))];
    }

    /**
     * The VAT of the decision by rate, from the archived XML. The goods items must add up to the total the decision
     * charges; when they do not, one line at the stated total keeps the ledger on what customs charged.
     *
     * @return array<string, array{base: string, amount: string}>
     */
    private function vatGroups(CustomsAssessmentDecision $decision, string $sign): array
    {
        $parsed = $this->parser->parse((string) $this->archive->contents($decision->xml_path));
        $groups = [];
        $sum = '0';

        foreach ($parsed->vatByRate as $group) {
            $amount = Decimal::round2($group['amount']);
            $sum = Decimal::add($sum, $amount);
            $groups[$group['rate']] = ['base' => Decimal::mul(Decimal::round2($group['base']), $sign), 'amount' => Decimal::mul($amount, $sign)];
        }

        $total = Decimal::round2((string) $decision->vat_amount);

        if ($groups === []) {
            return [self::UNRATED => ['base' => '0', 'amount' => Decimal::mul($total, $sign)]];
        }

        if (Decimal::compare($sum, $total) !== 0) {
            if (count($groups) !== 1) {
                throw new InvalidArgumentException("The VAT of the goods items ({$sum}) does not add up to the total of the decision ({$total}).");
            }

            $rate = array_key_first($groups);
            $groups[$rate]['amount'] = Decimal::mul($total, $sign);
        }

        return $groups;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return list<array<string, mixed>>
     */
    private function pair(string $accountId, string $signedAmount, array $extra = []): array
    {
        $amount = Decimal::round2(Decimal::abs($signedAmount));

        return [[
            'account_id' => $accountId,
            'debit_amount' => Decimal::isPositive($signedAmount) ? $amount : '0.00',
            'credit_amount' => Decimal::isPositive($signedAmount) ? '0.00' : $amount,
            'currency' => self::CURRENCY,
            ...$extra,
        ]];
    }

    /**
     * The version of the same document already in the ledger, the one a correction is compared with.
     */
    private function previousPosted(CustomsAssessmentDecision $decision): ?CustomsAssessmentDecision
    {
        $latest = CustomsAssessmentDecision::query()
            ->where('mrn', $decision->mrn)
            ->where('document_type', $decision->document_type->value)
            ->whereKeyNot($decision->id)
            ->whereHas('ledgerPosting', static fn ($query) => $query->where('status', LedgerPostingStatus::Posted->value))
            ->orderByDesc('version')
            ->first();

        if ($latest !== null && $latest->version > $decision->version) {
            throw new InvalidArgumentException("Version {$latest->version} of this decision is already posted.");
        }

        return $latest;
    }

    private function ownAccount(string $tenantId, ?string $accountId, string $label): string
    {
        $exists = $accountId !== null && ChartOfAccount::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereKey($accountId)->exists();

        if (! $exists) {
            throw new InvalidArgumentException("The {$label} account is not configured in the customs settings.");
        }

        return (string) $accountId;
    }

    /**
     * The account must be one the VAT flow reads, otherwise the tax would be deducted by nobody.
     */
    private function inputVatAccount(string $tenantId, ?CustomsSettings $settings): string
    {
        $inputAccounts = $this->vatAccounts->accountsFor($tenantId, 'CH')->inputAccountIds;
        $chosen = $settings?->import_vat_account_id ?? ($inputAccounts[0] ?? null);

        if ($chosen === null || ! in_array((string) $chosen, $inputAccounts, true)) {
            throw new InvalidArgumentException('The import VAT account must be an input VAT account of the VAT settings.');
        }

        return (string) $chosen;
    }
}
