<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Requests;

use App\Services\Tenancy\TenantClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Analytics\Services\KpiCatalog;
use Modules\Analytics\Support\ReportPeriod;

/**
 * Period, comparison and indicator selection shared by the KPI and export endpoints (access is decided by the
 * module.access middleware).
 */
final class KpiQueryRequest extends FormRequest
{
    /**
     * Access is decided by the module.access middleware.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare' => ['nullable', Rule::in([ReportPeriod::CompareNone, ReportPeriod::ComparePreviousPeriod, ReportPeriod::ComparePreviousYear])],
            'source' => ['nullable', Rule::in(['live', 'snapshot'])],
            'kpis' => ['nullable', 'array', 'max:'.count(KpiCatalog::keys())],
            'kpis.*' => ['string', Rule::in(KpiCatalog::keys())],
        ];
    }

    /**
     * The period of the request: the current month up to today unless dates are given.
     *
     * @throws ValidationException when the period is longer than allowed
     */
    public function period(): ReportPeriod
    {
        $today = app(TenantClock::class)->today();
        $to = $this->filled('to') ? CarbonImmutable::parse((string) $this->input('to')) : ($this->filled('from') ? CarbonImmutable::parse((string) $this->input('from'))->endOfMonth() : $today);
        $from = $this->filled('from') ? CarbonImmutable::parse((string) $this->input('from')) : $to->startOfMonth();

        try {
            return new ReportPeriod($from->startOfDay(), $to->startOfDay(), (string) $this->input('compare', ReportPeriod::CompareNone));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['to' => $exception->getMessage()]);
        }
    }

    /**
     * Whether the caller opted in to the materialised daily snapshots (`source=snapshot`). The default is the live
     * calculation, whose response shape does not change.
     */
    public function wantsSnapshots(): bool
    {
        return $this->input('source') === 'snapshot';
    }

    /**
     * Indicator keys asked for; empty means all of them.
     *
     * @return list<string>
     */
    public function kpiKeys(): array
    {
        return array_values(array_unique((array) $this->input('kpis', [])));
    }
}
