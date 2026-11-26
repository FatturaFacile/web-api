<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\GeneralLedger\Models\FxRevaluation as LedgerFxRevaluation;
use Modules\Treasury\Currency\Application\Commands\PostFxRevaluationCommand;
use Modules\Treasury\Currency\Domain\Enums\FxResult;
use Modules\Treasury\Currency\Domain\Enums\RevaluationStatus;
use Modules\Treasury\Currency\Domain\Enums\UnrealizedGainTreatment;
use Modules\Treasury\Currency\Domain\Events\FxRevaluationPosted;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluationLine;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencySettingsRepository;
use Modules\Treasury\Currency\Infrastructure\Persistence\FxLedgerPoster;

/**
 * Posts a draft revaluation to the General Ledger, once. Idempotent: the same Idempotency-Key on a posted revaluation answers the
 * posted revaluation again; any other key on it is a conflict (409). Losses are always booked; a gain follows the company
 * treatment (recognised, booked to a provision/reserve account, or left out). The entry is reversed on the first day of the next
 * period by the reversal job (or on request). A revaluation of the same day made by the General Ledger open-item service is a
 * conflict: the same positions would be revalued twice.
 */
final readonly class PostFxRevaluationHandler
{
    public function __construct(
        private CurrencySettingsRepository $settings,
        private FxLedgerPoster $poster,
    ) {}

    /**
     * @throws CurrencyDomainException
     */
    public function handle(PostFxRevaluationCommand $command): CurrencyFxRevaluation
    {
        [$posted, $fresh] = DB::transaction(function () use ($command): array {
            TransactionLock::acquire('currency_fx_revaluation:'.$command->revaluationId);

            $revaluation = CurrencyFxRevaluation::query()->withoutGlobalScopes()->where('tenant_id', $command->tenantId)->whereNull('deleted_at')->whereKey($command->revaluationId)->lockForUpdate()->first();

            if ($revaluation === null) {
                throw new CurrencyDomainException(__('currency_fx.errors.revaluation_not_found'), 'revaluation_not_found', 404);
            }

            if ($revaluation->status === RevaluationStatus::Posted && $revaluation->idempotency_key === $command->idempotencyKey) {
                return [$revaluation->load('lines'), false];
            }

            if ($revaluation->status === RevaluationStatus::Posted) {
                throw new CurrencyDomainException(__('currency_fx.errors.revaluation_already_posted'), 'revaluation_already_posted', 409);
            }

            if ($revaluation->status === RevaluationStatus::Reversed) {
                throw new CurrencyDomainException(__('currency_fx.errors.revaluation_already_reversed'), 'revaluation_already_reversed', 409);
            }

            $this->assertNoConflict($revaluation);

            $entryId = $this->postEntry($revaluation, $command->actorId);

            $revaluation->forceFill([
                'status' => RevaluationStatus::Posted->value,
                'journal_entry_id' => $entryId,
                'posted_at' => CarbonImmutable::now(),
                'posted_by' => $command->actorId,
                'idempotency_key' => $command->idempotencyKey,
                'updated_by' => $command->actorId,
            ])->save();

            return [$revaluation->load('lines'), true];
        });

        if ($fresh) {
            Event::dispatch(new FxRevaluationPosted($command->tenantId, (string) $posted->id, $posted->period_end->toDateString(), (string) $posted->net_result, $posted->journal_entry_id));
        }

        return $posted;
    }

    private function assertNoConflict(CurrencyFxRevaluation $revaluation): void
    {
        $periodEnd = $revaluation->period_end->toDateString();

        $other = CurrencyFxRevaluation::query()->withoutGlobalScopes()
            ->where('tenant_id', $revaluation->tenant_id)
            ->whereNull('deleted_at')
            ->where('status', RevaluationStatus::Posted->value)
            ->whereDate('period_end', $periodEnd)
            ->whereKeyNot($revaluation->getKey())
            ->exists();

        $ledger = LedgerFxRevaluation::withoutGlobalScopes()
            ->where('tenant_id', $revaluation->tenant_id)
            ->whereDate('revaluation_date', $periodEnd)
            ->whereNotNull('journal_entry_id')
            ->exists();

        if ($other || $ledger) {
            throw new CurrencyDomainException(__('currency_fx.errors.revaluation_period_conflict', ['date' => $periodEnd]), 'revaluation_period_conflict', 409);
        }
    }

    /**
     * One entry for the whole revaluation: per control account and counter account the net difference, gains against the gain
     * (or provision) account, losses against the loss account. Suppressed gains and neutral lines post nothing.
     */
    private function postEntry(CurrencyFxRevaluation $revaluation, ?string $actorId): ?string
    {
        $tenantId = (string) $revaluation->tenant_id;
        $settings = $this->settings->effective($tenantId);
        $pairs = [];

        /** @var CurrencyFxRevaluationLine $line */
        foreach ($revaluation->lines as $line) {
            if ($line->result === FxResult::Neutral || $line->treatment === UnrealizedGainTreatment::Suppress && $line->result === FxResult::Gain) {
                continue;
            }

            if ($line->account_id === null) {
                throw new CurrencyDomainException(__('currency_fx.errors.line_account_missing', ['reference' => (string) $line->reference]), 'fx_account_missing', 422);
            }

            $kind = $line->result === FxResult::Loss
                ? FxLedgerPoster::UNREALIZED_LOSS
                : ($line->treatment === UnrealizedGainTreatment::Provision ? FxLedgerPoster::UNREALIZED_GAIN_PROVISION : FxLedgerPoster::UNREALIZED_GAIN);
            $counter = $this->poster->counterAccountId($tenantId, $settings, $kind);
            $key = $line->account_id.'|'.$counter.'|'.$line->result->value;
            $pairs[$key] ??= ['control' => (string) $line->account_id, 'counter' => $counter, 'result' => $line->result, 'amount' => '0'];
            $pairs[$key]['amount'] = Dec::add($pairs[$key]['amount'], Dec::abs((string) $line->difference));
        }

        $label = sprintf('FX revaluation at %s', $revaluation->period_end->toDateString());
        $lines = [];

        foreach ($pairs as $pair) {
            $amount = Dec::money($pair['amount']);

            if (Dec::isZero($amount)) {
                continue;
            }

            $control = ['account_id' => $pair['control'], 'currency' => $settings->functionalCurrency, 'description' => $label];
            $counter = ['account_id' => $pair['counter'], 'currency' => $settings->functionalCurrency, 'description' => $label];

            // A gain raises an asset (or lowers a liability): debit the control account; a loss is the opposite.
            array_push(
                $lines,
                ...($pair['result'] === FxResult::Gain
                    ? [$control + ['debit_amount' => $amount, 'credit_amount' => '0'], $counter + ['debit_amount' => '0', 'credit_amount' => $amount]]
                    : [$counter + ['debit_amount' => $amount, 'credit_amount' => '0'], $control + ['debit_amount' => '0', 'credit_amount' => $amount]]),
            );
        }

        if ($lines === []) {
            return null;
        }

        return (string) $this->poster->post($tenantId, $revaluation->period_end->toDateString(), 'currency_fx_revaluation', (string) $revaluation->id, $label, $lines, $actorId)->id;
    }
}
