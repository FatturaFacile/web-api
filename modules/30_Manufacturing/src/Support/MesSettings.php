<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Support;

use Modules\Manufacturing\Models\MfgAccountingSetting;

/**
 * Tenant settings of the MES integration.
 */
final class MesSettings
{
    public const string MachineTimeManual = 'manual';

    public const string MachineTimeMes = 'mes';

    /**
     * Where the machine time of an operation comes from: `manual` (confirmations, the default) or `mes` (the delta
     * postings of the MES Core are costed).
     */
    public static function machineTimeSource(string $tenantId): string
    {
        $source = MfgAccountingSetting::query()->where('tenant_id', $tenantId)->value('machine_time_source');

        return $source === self::MachineTimeMes ? self::MachineTimeMes : self::MachineTimeManual;
    }
}
