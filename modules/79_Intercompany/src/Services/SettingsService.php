<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use Modules\GeneralLedger\Models\ConsolidationGroup;
use Modules\Intercompany\Models\GroupSettings;
use Modules\Intercompany\Support\GroupScope;

/**
 * The mirror, payment sync and netting options of a group. A group without a row works with the defaults of the table; the
 * row is created on first read, in the books of the owner of the group whoever asks.
 */
final readonly class SettingsService
{
    public function __construct(private GroupScope $scope) {}

    public function forGroup(ConsolidationGroup $group): GroupSettings
    {
        return $this->scope->runAs((string) $group->tenant_id, fn (): GroupSettings => GroupSettings::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $group->tenant_id, 'group_id' => $group->id],
            ['mirror_mode' => 'draft', 'auto_mirror' => true, 'sync_payments' => true, 'netting_enabled' => true, 'amount_tolerance' => '1.0000', 'date_tolerance_days' => 5, 'default_standard' => 'fer30'],
        )->refresh());
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(ConsolidationGroup $group, array $values): GroupSettings
    {
        return $this->scope->runAs((string) $group->tenant_id, function () use ($group, $values): GroupSettings {
            $settings = $this->forGroup($group);
            $settings->fill($values)->save();

            return $settings->refresh();
        });
    }
}
