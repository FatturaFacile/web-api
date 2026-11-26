<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Treasury\Currency\Application\Commands\ReverseFxRevaluationCommand;
use Modules\Treasury\Currency\Domain\Enums\RevaluationStatus;
use Modules\Treasury\Currency\Domain\Events\FxRevaluationReversed;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;
use Modules\Treasury\Currency\Infrastructure\Persistence\FxLedgerPoster;

/**
 * Reverses a posted revaluation on the first day of the next period (or on a given later day), so that every period is
 * revalued again from the historical book values and a payment after the revaluation is measured against the original document
 * rate. Idempotent: a reversed revaluation answers as it is; a draft cannot be reversed (409).
 */
final readonly class ReverseFxRevaluationHandler
{
    public function __construct(private FxLedgerPoster $poster) {}

    /**
     * @throws CurrencyDomainException
     */
    public function handle(ReverseFxRevaluationCommand $command): CurrencyFxRevaluation
    {
        [$revaluation, $fresh] = DB::transaction(function () use ($command): array {
            TransactionLock::acquire('currency_fx_revaluation:'.$command->revaluationId);

            $revaluation = CurrencyFxRevaluation::query()->withoutGlobalScopes()->where('tenant_id', $command->tenantId)->whereNull('deleted_at')->whereKey($command->revaluationId)->lockForUpdate()->first();

            if ($revaluation === null) {
                throw new CurrencyDomainException(__('currency_fx.errors.revaluation_not_found'), 'revaluation_not_found', 404);
            }

            if ($revaluation->status === RevaluationStatus::Reversed) {
                return [$revaluation->load('lines'), false];
            }

            if ($revaluation->status !== RevaluationStatus::Posted) {
                throw new CurrencyDomainException(__('currency_fx.errors.revaluation_not_posted'), 'revaluation_not_posted', 409);
            }

            $periodEnd = $revaluation->period_end->toDateString();
            $date = CarbonImmutable::parse($command->reversalDate ?? ($revaluation->reverse_on?->toDateString() ?? $revaluation->period_end->addDay()->toDateString()))->toDateString();

            if ($date <= $periodEnd) {
                throw new CurrencyDomainException(__('currency_fx.errors.reversal_date_invalid', ['date' => $periodEnd]), 'reversal_date_invalid', 422, 'reversal_date');
            }

            $reversalId = $revaluation->journal_entry_id === null
                ? null
                : (string) $this->poster->reverse((string) $revaluation->journal_entry_id, $date, 'FX revaluation reversal', $command->actorId)->id;

            $revaluation->forceFill([
                'status' => RevaluationStatus::Reversed->value,
                'reversal_journal_entry_id' => $reversalId,
                'reversed_at' => CarbonImmutable::now(),
                'reverse_on' => $date,
                'updated_by' => $command->actorId,
            ])->save();

            return [$revaluation->load('lines'), true];
        });

        if ($fresh) {
            Event::dispatch(new FxRevaluationReversed($command->tenantId, (string) $revaluation->id, $revaluation->reverse_on?->toDateString() ?? '', $revaluation->reversal_journal_entry_id));
        }

        return $revaluation;
    }
}
