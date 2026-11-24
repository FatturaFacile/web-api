<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use Illuminate\Validation\ValidationException;
use Modules\Returns\Models\ReturnReasonCode;
use Modules\Returns\Models\ReturnsSetting;
use Modules\Returns\Models\Rma;
use Modules\Returns\Models\RmaLine;
use Modules\Returns\Models\SupplierReturn;
use Modules\Returns\Support\ReturnsError;

/**
 * The return window and the reason codes of a company. A company that has never opened the settings starts with the
 * default window and the default reason codes.
 */
final readonly class ReturnsSettingsService
{
    public function __construct(private ReturnsLedger $ledger) {}

    public function settings(string $tenantId): ReturnsSetting
    {
        $setting = ReturnsSetting::query()->where('tenant_id', $tenantId)->first();

        if ($setting === null) {
            $setting = ReturnsSetting::create(['tenant_id' => $tenantId, 'return_window_days' => ReturnsSetting::DefaultWindowDays]);
        }

        if (! ReturnReasonCode::withTrashed()->where('tenant_id', $tenantId)->exists()) {
            foreach (ReturnReasonCode::Defaults as $code => [$label, $isDefect]) {
                ReturnReasonCode::create(['tenant_id' => $tenantId, 'code' => $code, 'label' => $label, 'is_defect' => $isDefect, 'is_active' => true]);
            }
        }

        return $setting;
    }

    public function windowDays(string $tenantId): int
    {
        return $this->settings($tenantId)->return_window_days;
    }

    public function updateWindow(string $tenantId, int $days): ReturnsSetting
    {
        $setting = $this->settings($tenantId);
        $setting->update(['return_window_days' => $days]);
        $this->ledger->record('returns.settings_updated', $setting, ['return_window_days' => $days]);

        return $setting->refresh();
    }

    /**
     * @return list<ReturnReasonCode>
     */
    public function reasonCodes(string $tenantId, bool $onlyActive = false): array
    {
        $this->settings($tenantId);

        return ReturnReasonCode::query()
            ->where('tenant_id', $tenantId)
            ->when($onlyActive, static fn ($query) => $query->where('is_active', true))
            ->orderBy('code')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createReason(string $tenantId, array $data): ReturnReasonCode
    {
        $this->settings($tenantId);

        if (ReturnReasonCode::withTrashed()->where('tenant_id', $tenantId)->where('code', $data['code'])->exists()) {
            throw ReturnsError::on('code', 'reason.duplicate', ['code' => $data['code']]);
        }

        return ReturnReasonCode::create([
            'tenant_id' => $tenantId,
            'code' => $data['code'],
            'label' => $data['label'],
            'is_defect' => (bool) ($data['is_defect'] ?? false),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    /**
     * The code is fixed once created: only the label and the flags change.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateReason(ReturnReasonCode $reason, array $data): ReturnReasonCode
    {
        $reason->update(array_intersect_key($data, array_flip(['label', 'is_defect', 'is_active'])));

        return $reason->refresh();
    }

    /**
     * A code used by a return is deactivated; an unused one is removed.
     */
    public function deleteReason(ReturnReasonCode $reason): bool
    {
        $inUse = Rma::withTrashed()->where('tenant_id', $reason->tenant_id)->where('reason_code', $reason->code)->exists()
            || RmaLine::withTrashed()->where('tenant_id', $reason->tenant_id)->where('reason_code', $reason->code)->exists()
            || SupplierReturn::withTrashed()->where('tenant_id', $reason->tenant_id)->where('reason_code', $reason->code)->exists();

        if ($inUse) {
            $reason->update(['is_active' => false]);

            return false;
        }

        $reason->delete();

        return true;
    }

    /**
     * @throws ValidationException when the code does not exist or is inactive
     */
    public function assertActiveReason(string $tenantId, string $code, string $field = 'reason_code'): void
    {
        $this->settings($tenantId);
        $reason = ReturnReasonCode::query()->where('tenant_id', $tenantId)->where('code', $code)->first();

        if ($reason === null || ! $reason->is_active) {
            throw ReturnsError::on($field, 'reason.unknown', ['code' => $code]);
        }
    }
}
