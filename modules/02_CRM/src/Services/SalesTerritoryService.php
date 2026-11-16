<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\AccountTerritory;
use Modules\CRM\Models\SalesTerritory;

/**
 * Assigns accounts to sales territories by rule. A territory matches an account when every criteria list it defines
 * (countries of the primary contact, industries, account tiers) contains the account value; an empty list is no
 * constraint. The lowest priority number wins. A manual assignment is never overwritten by the rules.
 */
final class SalesTerritoryService
{
    /**
     * The best matching active territory of an account, or null.
     *
     * @param  Collection<int, SalesTerritory>|null  $territories  Active territories ordered by priority, loaded once for bulk runs
     */
    public function matchFor(Account $account, ?Collection $territories = null): ?SalesTerritory
    {
        $territories ??= $this->activeTerritories((string) $account->tenant_id);
        $country = str($account->primaryContact?->country ?? '')->upper()->toString();
        $industry = str((string) $account->industry)->lower()->toString();
        $tier = str((string) $account->tier)->lower()->toString();

        return $territories->first(function (SalesTerritory $territory) use ($country, $industry, $tier): bool {
            $criteria = (array) ($territory->criteria ?? []);

            return $this->listAllows($criteria['countries'] ?? [], $country, 'upper')
                && $this->listAllows($criteria['industries'] ?? [], $industry, 'lower')
                && $this->listAllows($criteria['tiers'] ?? [], $tier, 'lower');
        });
    }

    /**
     * Applies the rules to one account. Returns the resulting link or null when nothing matches.
     */
    public function assignAccount(Account $account, ?Collection $territories = null): ?AccountTerritory
    {
        $current = AccountTerritory::query()->where('tenant_id', $account->tenant_id)->where('account_id', $account->id)->first();

        if ($current !== null && $current->source === 'manual') {
            return $current;
        }

        $territory = $this->matchFor($account, $territories);

        if ($territory === null) {
            $current?->delete();

            return null;
        }

        return DB::transaction(function () use ($account, $territory, $current): AccountTerritory {
            $link = $current ?? new AccountTerritory(['tenant_id' => $account->tenant_id, 'account_id' => $account->id]);
            $link->fill(['territory_id' => $territory->id, 'source' => 'rule', 'assigned_at' => now()])->save();

            if ($territory->owner_user_id !== null && $account->owner_user_id === null) {
                $account->forceFill(['owner_user_id' => $territory->owner_user_id])->save();
            }

            return $link;
        });
    }

    /**
     * Pins an account to a territory by hand; rule runs keep it.
     */
    public function assignManually(Account $account, SalesTerritory $territory): AccountTerritory
    {
        $link = AccountTerritory::query()->where('tenant_id', $account->tenant_id)->where('account_id', $account->id)->first()
            ?? new AccountTerritory(['tenant_id' => $account->tenant_id, 'account_id' => $account->id]);
        $link->fill(['territory_id' => $territory->id, 'source' => 'manual', 'assigned_at' => now()])->save();

        return $link;
    }

    /**
     * Runs the rules over every account of the tenant.
     *
     * @return array{assigned: int, unmatched: int, kept_manual: int}
     */
    public function assignAll(string $tenantId): array
    {
        $territories = $this->activeTerritories($tenantId);
        $result = ['assigned' => 0, 'unmatched' => 0, 'kept_manual' => 0];

        Account::query()->where('tenant_id', $tenantId)->with('primaryContact')->chunkById(200, function ($accounts) use ($territories, &$result): void {
            foreach ($accounts as $account) {
                $link = $this->assignAccount($account, $territories);

                if ($link === null) {
                    $result['unmatched']++;
                } elseif ($link->source === 'manual') {
                    $result['kept_manual']++;
                } else {
                    $result['assigned']++;
                }
            }
        });

        return $result;
    }

    /**
     * @return Collection<int, SalesTerritory>
     */
    private function activeTerritories(string $tenantId): Collection
    {
        return SalesTerritory::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('priority')->orderBy('code')->get();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function listAllows(array $allowed, string $value, string $case): bool
    {
        if ($allowed === []) {
            return true;
        }

        $normalized = array_map(static fn (string $item): string => $case === 'upper' ? mb_strtoupper($item) : mb_strtolower($item), $allowed);

        return $value !== '' && in_array($value, $normalized, true);
    }
}
