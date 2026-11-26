<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Application\Handlers;

use App\Models\ExchangeRate as ExchangeRateRecord;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Treasury\Currency\Application\Commands\CreateManualRateCommand;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Exceptions\CurrencyDomainException;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;
use Modules\Treasury\Currency\Infrastructure\Persistence\ExchangeRateRepository;

/**
 * Stores a manual or contract rate of a company. A rate already stored for the pair, type and day is never edited (documents
 * that froze it stay as they are): the new value is a new version and the old one points to it.
 */
final readonly class CreateManualRateHandler
{
    public function __construct(private ExchangeRateRepository $rates) {}

    /**
     * @throws CurrencyDomainException
     */
    public function handle(CreateManualRateCommand $command): ExchangeRateRecord
    {
        try {
            $pair = CurrencyPair::of($command->base, $command->quote);
            $rate = ExchangeRateValue::strict($command->rate);
            $day = CarbonImmutable::parse($command->validOn)->toDateString();
        } catch (InvalidArgumentException $exception) {
            throw new CurrencyDomainException($exception->getMessage(), 'invalid_rate', 422, 'rate');
        }

        if ($pair->isIdentity()) {
            throw new CurrencyDomainException(__('currency_fx.errors.same_currency'), 'same_currency_pair', 422, 'quote');
        }

        $reason = $command->reason === null ? null : trim($command->reason);

        if ($command->type === RateType::Contract && ($reason === null || $reason === '')) {
            throw new CurrencyDomainException(__('currency_fx.errors.contract_reason_required'), 'contract_reason_required', 422, 'reason');
        }

        return $this->rates->record(
            tenantId: $command->tenantId,
            pair: $pair,
            rate: $rate,
            type: $command->type,
            source: RateSource::Manual,
            validOn: $day,
            derived: false,
            reason: $reason === '' ? null : $reason,
            contractReference: $command->contractReference === null || trim($command->contractReference) === '' ? null : trim($command->contractReference),
            actorId: $command->actorId,
        )['record'];
    }
}
