<?php

declare(strict_types=1);

namespace Modules\AssetManagement\Services;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Modules\AssetManagement\Models\FixedAsset;
use Modules\AssetManagement\Notifications\AssetCoverageExpiringNotification;

/**
 * Warranty and insurance expiry of the assets: the list of coverages expiring within a horizon, and the alerts sent
 * once per expiry date to the owners, administrators and accountants of the company and to the custodian.
 */
final class AssetCoverageAlertService
{
    /** Roles of the company told about expiring coverages. */
    private const array RecipientRoles = ['owner', 'admin', 'accountant'];

    /**
     * Assets whose warranty or insurance expires between today and the horizon (already expired ones included when
     * $includeExpired is true).
     *
     * @return Builder<FixedAsset>
     */
    public function expiringQuery(string $tenantId, int $withinDays, bool $includeExpired = false): Builder
    {
        $today = now()->toDateString();
        $until = now()->addDays($withinDays)->toDateString();
        $range = static function (Builder $query, string $column) use ($today, $until, $includeExpired): void {
            $query->whereNotNull($column)->whereDate($column, '<=', $until);
            if (! $includeExpired) {
                $query->whereDate($column, '>=', $today);
            }
        };

        return FixedAsset::where('tenant_id', $tenantId)
            ->whereNotIn('status', AssetLifecycleService::ClosedStatuses)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $warranty) => $range($warranty, 'warranty_expiry_date'))
                ->orWhere(fn (Builder $insurance) => $range($insurance, 'insurance_expiry_date')))
            ->orderBy('asset_number');
    }

    /**
     * Sends the alerts not sent yet for the coverages expiring within the horizon. Returns the alerts sent.
     */
    public function notifyExpiring(string $tenantId, int $withinDays = 30): int
    {
        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            return 0;
        }

        $managers = $tenant->users()->wherePivotIn('role', self::RecipientRoles)->get();
        $sent = 0;

        $this->expiringQuery($tenantId, $withinDays)->lazyById(200)->each(function (FixedAsset $asset) use ($managers, $withinDays, &$sent): void {
            $limit = now()->addDays($withinDays)->startOfDay();

            foreach ([AssetCoverageExpiringNotification::Warranty, AssetCoverageExpiringNotification::Insurance] as $coverage) {
                $expiry = $asset->{$coverage.'_expiry_date'};
                if ($expiry === null || $asset->{$coverage.'_alerted_at'} !== null || $expiry->startOfDay()->gt($limit) || $expiry->startOfDay()->lt(now()->startOfDay())) {
                    continue;
                }

                $recipients = $this->recipients($managers, $asset);
                if ($recipients->isNotEmpty()) {
                    Notification::send($recipients, new AssetCoverageExpiringNotification(
                        coverage: $coverage,
                        assetId: (string) $asset->id,
                        assetNumber: (string) $asset->asset_number,
                        assetName: (string) $asset->name,
                        expiryDate: $expiry->format('Y-m-d'),
                        provider: $asset->{$coverage.'_provider'},
                    ));
                    $sent++;
                }

                $asset->forceFill([$coverage.'_alerted_at' => now()])->save();
            }
        });

        return $sent;
    }

    /**
     * Managers of the company plus the custodian of the asset, once each.
     *
     * @param  Collection<int, User>  $managers
     * @return Collection<int, User>
     */
    private function recipients(Collection $managers, FixedAsset $asset): Collection
    {
        $recipients = $managers;

        if ($asset->custodian_user_id !== null && ! $managers->contains('id', $asset->custodian_user_id)) {
            $custodian = User::query()->find($asset->custodian_user_id);
            if ($custodian !== null) {
                $recipients = $managers->concat([$custodian]);
            }
        }

        return $recipients->values();
    }
}
