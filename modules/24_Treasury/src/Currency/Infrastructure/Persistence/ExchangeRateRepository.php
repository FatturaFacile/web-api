<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Persistence;

use App\Models\ExchangeRate as ExchangeRateRecord;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use App\Support\Database\TransactionLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Enums\RateType;
use Modules\Treasury\Currency\Domain\Support\Dec;
use Modules\Treasury\Currency\Domain\ValueObjects\CurrencyPair;
use Modules\Treasury\Currency\Domain\ValueObjects\ExchangeRateValue;

/**
 * Writes rates. A rate is never edited: storing a different value for a pair, type, day and owner inserts a new version and the
 * old one points to it (`superseded_by`), per publisher: the ECB and the SNB each keep their own rate of a pair and day, so a document that froze the old value is untouched and the history stays. The same
 * value stored again is a no-op (the sync is idempotent). Official rates (no owner) are written only by the platform code.
 */
final readonly class ExchangeRateRepository
{
    public function __construct(private TenantContext $tenants) {}

    /**
     * @return array{record: ExchangeRateRecord, created: bool}
     */
    public function record(
        ?string $tenantId,
        CurrencyPair $pair,
        ExchangeRateValue $rate,
        RateType $type,
        RateSource $source,
        string $validOn,
        bool $derived = false,
        ?string $reason = null,
        ?string $contractReference = null,
        ?string $actorId = null,
    ): array {
        return DB::transaction(function () use ($tenantId, $pair, $rate, $type, $source, $validOn, $derived, $reason, $contractReference, $actorId): array {
            TransactionLock::acquire(sprintf('exchange_rate:%s:%s:%s:%s', $tenantId ?? 'official', $pair->key(), $type->value, $validOn));

            $current = $this->scope($tenantId)
                ->where('source', $pair->base->value)
                ->where('target', $pair->quote->value)
                ->where('rate_type', $type->value)
                ->where('rate_source', $source->value)
                ->whereDate('effective_date', $validOn)
                ->whereNull('superseded_by')
                ->first();

            if ($current !== null && Dec::cmp((string) $current->rate, $rate->value) === 0 && (string) $current->contract_reference === (string) $contractReference) {
                return ['record' => $current, 'created' => false];
            }

            $version = 1 + (int) $this->scope($tenantId)
                ->where('source', $pair->base->value)
                ->where('target', $pair->quote->value)
                ->where('rate_type', $type->value)
                ->where('rate_source', $source->value)
                ->whereDate('effective_date', $validOn)
                ->max('version');

            $attributes = [
                'source' => $pair->base->value,
                'target' => $pair->quote->value,
                'rate' => $rate->value,
                'effective_date' => $validOn,
                'rate_type' => $type->value,
                'rate_source' => $source->value,
                'is_derived' => $derived,
                'version' => $version,
                'reason' => $reason,
                'contract_reference' => $contractReference,
                'created_by' => $actorId,
            ];

            $record = $tenantId === null
                ? $this->createOfficial($attributes)
                : $this->createOwned($tenantId, $attributes);

            if ($current !== null) {
                // A mass update on purpose: the superseded row is history, changed by this code only.
                ExchangeRateRecord::query()->withoutGlobalScopes()->whereKey($current->getKey())->update(['superseded_by' => $record->getKey()]);
            }

            return ['record' => $record, 'created' => true];
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOfficial(array $attributes): ExchangeRateRecord
    {
        return $this->tenants->runWithoutTenant(fn (): ExchangeRateRecord => $this->tenants->runAsGlobal(
            static fn (): ExchangeRateRecord => ExchangeRateRecord::query()->create($attributes + ['tenant_id' => null]),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOwned(string $tenantId, array $attributes): ExchangeRateRecord
    {
        // Written in the context of the owning company, whatever the caller's context is (a job, a console command, a test).
        return $this->tenants->runWithTenant(Tenant::query()->findOrFail($tenantId), static function () use ($tenantId, $attributes): ExchangeRateRecord {
            $record = new ExchangeRateRecord;
            $record->forceFill($attributes + ['tenant_id' => $tenantId]);
            $record->save();

            return $record;
        });
    }

    /**
     * @return Builder<ExchangeRateRecord>
     */
    private function scope(?string $tenantId): Builder
    {
        $query = ExchangeRateRecord::query()->withoutGlobalScopes();

        return $tenantId === null ? $query->whereNull('tenant_id') : $query->where('tenant_id', $tenantId);
    }
}
