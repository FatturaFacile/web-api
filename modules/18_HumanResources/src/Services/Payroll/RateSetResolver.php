<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\Payroll;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;
use Modules\HumanResources\Contracts\Payroll\RateSetResolverInterface;
use Modules\HumanResources\DTOs\Payroll\RateSet;
use Modules\HumanResources\Exceptions\Payroll\MissingRateSetException;
use Modules\HumanResources\Exceptions\Payroll\PayrollCalculationException;
use Modules\HumanResources\Models\Payroll\PayrollRateSet;

final class RateSetResolver implements RateSetResolverInterface
{
    public function resolve(DateTimeInterface $date, string $periodCode): RateSet
    {
        $day = $date->format('Y-m-d');

        // The shared-lookup tenant scope returns the active tenant's rows plus the system defaults.
        $candidates = PayrollRateSet::query()
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', $day)
            ->where(function (Builder $query) use ($day): void {
                $query->whereNull('valid_to')->orWhereDate('valid_to', '>=', $day);
            })
            ->orderByDesc('valid_from')
            ->get();

        $model = $candidates->first(fn (PayrollRateSet $candidate): bool => $candidate->tenant_id !== null)
            ?? $candidates->first();

        if ($model === null) {
            throw MissingRateSetException::forPeriod($periodCode);
        }

        try {
            return RateSet::fromArray($model->rates, (string) $model->id, $model->code, $model->year);
        } catch (InvalidArgumentException $exception) {
            throw PayrollCalculationException::invalid(
                "Rate set {$model->code} is malformed: {$exception->getMessage()}",
                ['rate_set' => [$exception->getMessage()]],
            );
        }
    }
}
