<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A photo of the scene attached to an incident report; the file is on the private disk.
 *
 * @property string $incident_report_id
 * @property string $file_path
 * @property string $mime_type
 * @property int $size_bytes
 */
final class IncidentPhoto extends BaseEnterpriseModel
{
    protected $table = 'hse_incident_photos';

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
