<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * @property string $toolbox_talk_id
 * @property string $employee_id
 * @property string $signature_hash
 * @property string|null $signature_image_base64_or_ref
 * @property Carbon $signed_at
 */
final class ToolBoxTalkAttendance extends BaseEnterpriseModel
{
    protected $table = 'hse_toolbox_talk_attendances';

    /** @var list<string> */
    protected $hidden = ['signature_image_base64_or_ref'];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function auditPayload(array $attributes): array
    {
        unset($attributes['signature_image_base64_or_ref']);

        return $attributes;
    }
}
