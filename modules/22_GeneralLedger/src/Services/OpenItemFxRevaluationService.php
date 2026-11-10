<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use App\Models\ExchangeRate;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Exceptions\LedgerPostingException;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\FxRevaluation;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\LedgerSetting;
use Modules\GeneralLedger\Models\SupplierLedger;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\GeneralLedger\Services\Tax\TenantAccountingCurrency;

/**
 * Period-end revaluation of the open receivables and payables in a foreign currency, with the convention Treasury
 * uses for the bank accounts (BankFxRevaluationService):
 *
 * - closing rate: the exchange-rate service (`exchange_rates`, source = accounting currency, target = item currency,
 *   latest rate on or before the date, foreign units per accounting unit); without a rate nothing is revalued;
 * - book value: the open amount at the historical rate of the line that opened the item (`journal_lines.exchange_rate`,
 *   accounting units per foreign unit);
 * - revalued value: the open amount divided by the closing rate, to the cent.
 *
 * The difference is posted per currency and control account, in the accounting currency and without partner (no new
 * open item), against the unrealised exchange gain or loss account of the default posting accounts, which fall back to
 * the Treasury `banking.ledger_defaults.fx_unrealised_*_account_code`. The entry is reversed automatically on the day
 * after, so every revaluation starts again from the historical book value. Once per date, side, currency and account.
 */
final readonly class OpenItemFxRevaluationService
{
    public function __construct(
        private DoubleEntryPostingServiceInterface $postingService,
        private PostingAccountResolver $postingAccounts,
        private FiscalPeriodResolver $periods,
    ) {}

    /**
     * @return list<FxRevaluation>
     *
     * @throws LedgerPostingException when a rate or a gain/loss account is missing
     */
    public function revalue(string $tenantId, CarbonImmutable $date, ?string $currency, string $actorId): array
    {
        $base = TenantAccountingCurrency::of($tenantId);
        $groups = [];

        foreach (['receivable' => CustomerLedger::class, 'payable' => SupplierLedger::class] as $side => $ledger) {
            $partnerColumn = $side === 'receivable' ? 'customer_id' : 'supplier_id';
            $items = $ledger::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->where('currency', '!=', $base)
                ->when($currency !== null, fn ($query) => $query->where('currency', strtoupper((string) $currency)))
                ->whereIn('status', ['open', 'partially_paid'])
                ->whereDate('posting_date', '<=', $date->toDateString())
                ->get();

            foreach ($items as $item) {
                if (Decimal::isZero((string) $item->open_amount)) {
                    continue;
                }

                $line = JournalLine::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('journal_entry_id', $item->journal_entry_id)
                    ->where('partner_id', $item->getAttribute($partnerColumn))
                    ->orderBy('line_number')
                    ->first(['account_id', 'exchange_rate']);

                if ($line === null) {
                    continue;
                }

                $key = "{$side}|{$item->currency}|{$line->account_id}";
                $groups[$key] ??= ['side' => $side, 'currency' => (string) $item->currency, 'account_id' => (string) $line->account_id, 'open' => '0', 'book' => '0', 'items' => 0];
                $groups[$key]['open'] = bcadd($groups[$key]['open'], (string) $item->open_amount, 4);
                $groups[$key]['book'] = bcadd($groups[$key]['book'], bcmul((string) $item->open_amount, (string) $line->exchange_rate, 8), 8);
                $groups[$key]['items']++;
            }
        }

        if ($groups === []) {
            throw new LedgerPostingException('No open receivable or payable in a foreign currency to revalue at '.$date->toDateString().'.');
        }

        return DB::transaction(fn (): array => array_values(array_map(fn (array $group): FxRevaluation => $this->revalueGroup($tenantId, $base, $date, $group, $actorId), $groups)));
    }

    /**
     * @param  array{side: string, currency: string, account_id: string, open: string, book: string, items: int}  $group
     */
    private function revalueGroup(string $tenantId, string $base, CarbonImmutable $date, array $group, string $actorId): FxRevaluation
    {
        $existing = FxRevaluation::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereDate('revaluation_date', $date->toDateString())
            ->where('side', $group['side'])
            ->where('currency', $group['currency'])
            ->where('account_id', $group['account_id'])
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $rate = $this->closingRate($base, $group['currency'], $date);
        $book = Decimal::round2($group['book']);
        $revalued = Decimal::round2(Decimal::div($group['open'], $rate));
        $difference = bcsub($revalued, $book, 2);

        $revaluation = FxRevaluation::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'revaluation_date' => $date->toDateString(),
            'side' => $group['side'],
            'currency' => $group['currency'],
            'account_id' => $group['account_id'],
            'closing_rate' => $rate,
            'open_amount' => $group['open'],
            'book_value' => $book,
            'revalued_value' => $revalued,
            'difference' => $difference,
            'open_items' => $group['items'],
            'created_by' => $actorId,
        ]);

        if (bccomp($difference, '0', 2) === 0) {
            return $revaluation;
        }

        $increasesBalance = bccomp($difference, '0', 2) === 1;
        $isGain = $group['side'] === 'receivable' ? $increasesBalance : ! $increasesBalance;
        $treatment = $isGain ? $this->resolveUnrealisedGainTreatment($tenantId) : 'recognise';

        if ($isGain && $treatment !== 'recognise') {
            $provisionAccount = $treatment === 'provision'
                ? $this->postingAccounts->account($tenantId, PostingAccountRole::FxUnrealisedGainProvision)
                : null;

            if ($provisionAccount === null) {
                $revaluation->update([
                    'imparity_deferred' => true,
                    'treatment_applied' => 'suppress',
                ]);

                return $revaluation->refresh();
            }

            $counter = $provisionAccount;
            $imparityDeferred = true;
            $treatmentApplied = 'provision';
        } else {
            $counter = $this->postingAccounts->require($tenantId, $isGain ? PostingAccountRole::FxUnrealisedGain : PostingAccountRole::FxUnrealisedLoss);
            $imparityDeferred = false;
            $treatmentApplied = 'recognise';
        }

        $amount = ltrim($difference, '-');
        $label = "FX revaluation of open {$group['side']}s in {$group['currency']} at {$date->toDateString()} (rate {$rate})";
        $controlIsDebit = $group['side'] === 'receivable' ? $increasesBalance : ! $increasesBalance;
        $control = ['account_id' => $group['account_id'], 'currency' => $base, 'description' => $label];
        $other = ['account_id' => (string) $counter->id, 'currency' => $base, 'description' => $label];

        $entry = $this->postingService->createAndPost([
            'tenant_id' => $tenantId,
            'fiscal_period_id' => (string) $this->periods->forDate($tenantId, $date)->id,
            'posting_date' => $date->toDateString(),
            'document_date' => $date->toDateString(),
            'journal_type' => 'adjusting',
            'description' => $label,
            'source_document_type' => 'gl_fx_revaluation',
            'source_document_id' => (string) $revaluation->id,
            'auto_reverse_on' => $date->addDay()->toDateString(),
            'bank_balance_managed' => true,
            'lines' => $controlIsDebit
                ? [$control + ['debit_amount' => $amount, 'credit_amount' => '0'], $other + ['debit_amount' => '0', 'credit_amount' => $amount]]
                : [$other + ['debit_amount' => $amount, 'credit_amount' => '0'], $control + ['debit_amount' => '0', 'credit_amount' => $amount]],
        ], $actorId);

        $revaluation->update([
            'journal_entry_id' => $entry->id,
            'reversal_date' => $date->addDay()->toDateString(),
            'imparity_deferred' => $imparityDeferred,
            'treatment_applied' => $treatmentApplied,
        ]);

        return $revaluation->refresh();
    }

    /**
     * Resolves CO Art. 960a imparity treatment for unrealised FX gains ('provision', 'suppress', or 'recognise').
     */
    private function resolveUnrealisedGainTreatment(string $tenantId): string
    {
        $setting = LedgerSetting::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if ($setting !== null && $setting->enforce_swiss_imparity_principle === false) {
            return 'recognise';
        }

        if ($setting !== null && in_array($setting->unrealised_gain_treatment, ['provision', 'suppress', 'recognise'], true)) {
            return (string) $setting->unrealised_gain_treatment;
        }

        $country = strtoupper((string) (Tenant::find($tenantId)?->getAttribute('country') ?? 'IT'));

        return $country === 'CH' ? 'provision' : 'recognise';
    }

    /**
     * Units of the foreign currency for one unit of the accounting currency, from the exchange-rate service.
     *
     * @throws LedgerPostingException when no rate was published on or before the date
     */
    private function closingRate(string $base, string $currency, CarbonImmutable $date): string
    {
        $rate = ExchangeRate::query()->where('source', $base)->where('target', $currency)->whereDate('effective_date', '<=', $date->toDateString())->latest('effective_date')->value('rate');

        if ($rate === null || bccomp((string) $rate, '0', 8) !== 1) {
            throw new LedgerPostingException("No {$base}/{$currency} exchange rate on or before {$date->toDateString()}: synchronise the exchange rates first.");
        }

        return (string) $rate;
    }
}
