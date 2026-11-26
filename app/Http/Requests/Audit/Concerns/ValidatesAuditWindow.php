<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit\Concerns;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The filters shared by the search and the export of the ledger, and the rule that a date window never exceeds the
 * configured maximum (366 days).
 */
trait ValidatesAuditWindow
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function filterRules(): array
    {
        return [
            'actor' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:50'],
            'resource_type' => ['nullable', 'string', 'max:120'],
            'company_id' => ['nullable', 'uuid'],
            'channel' => ['nullable', 'string', 'in:web,desktop,mobile,api'],
            'category' => ['nullable', 'string', 'in:crud,business,security,access,platform'],
            'ip' => ['nullable', 'ip'],
        ];
    }

    protected function assertWindowWithinLimit(Validator $validator): void
    {
        $from = $this->input('from');
        $to = $this->input('to');

        if (! is_string($from) || ! is_string($to) || $validator->errors()->hasAny(['from', 'to'])) {
            return;
        }

        $maxDays = (int) config('audit_ledger.export.max_window_days', 366);

        try {
            $days = Carbon::createFromFormat('Y-m-d', $from, 'UTC')->startOfDay()->diffInDays(Carbon::createFromFormat('Y-m-d', $to, 'UTC')->startOfDay());
        } catch (Throwable) {
            return;
        }

        if ($days > $maxDays) {
            $validator->errors()->add('to', "The date range cannot exceed {$maxDays} days.");
        }
    }

    /**
     * The filters present in the request.
     *
     * @return array<string, string>
     */
    protected function presentFilters(): array
    {
        return array_filter(
            $this->only(['actor', 'action', 'resource_type', 'company_id', 'channel', 'category', 'ip']),
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        );
    }
}
