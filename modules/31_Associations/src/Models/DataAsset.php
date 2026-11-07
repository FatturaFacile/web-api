<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * Where personal data lives: a physical archive, a system or a group of people, rated for confidentiality, integrity
 * and availability on a scale from 1 (low) to 4 (very high), as the base of the risk analysis.
 *
 * @property int $confidentiality
 * @property int $integrity
 * @property int $availability
 */
final class DataAsset extends AssociationRecord
{
    protected $table = 'assoc_data_assets';

    protected $appends = ['rating'];

    protected function casts(): array
    {
        return ['confidentiality' => 'integer', 'integrity' => 'integer', 'availability' => 'integer', 'activity_family_ids' => 'array'];
    }

    /**
     * The highest of the three scores, which drives the protection the asset needs.
     */
    public function getRatingAttribute(): int
    {
        return max((int) $this->confidentiality, (int) $this->integrity, (int) $this->availability);
    }
}
