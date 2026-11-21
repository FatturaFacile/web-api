<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * An industrial edge gateway (OPC-UA, Modbus TCP, MQTT, CNC adapters) allowed to push machine telemetry. Requests are
 * authenticated by the HMAC-SHA256 signature of the body with the shared secret, which is stored encrypted.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $name
 * @property string $secret
 * @property bool $is_active
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgEdgeGateway extends BaseEnterpriseModel
{
    protected $table = 'mfg_edge_gateways';

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }
}
