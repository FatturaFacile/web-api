<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\GroupSettings;

/**
 * @mixin GroupSettings
 */
final class SettingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'group_id' => (string) $this->group_id,
            'mirror_mode' => $this->mirror_mode,
            'auto_mirror' => (bool) $this->auto_mirror,
            'sync_payments' => (bool) $this->sync_payments,
            'netting_enabled' => (bool) $this->netting_enabled,
            'amount_tolerance' => (string) $this->amount_tolerance,
            'date_tolerance_days' => (int) $this->date_tolerance_days,
            'default_standard' => $this->default_standard,
        ];
    }
}
