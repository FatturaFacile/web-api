<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\Manufacturing\Enums\DefectReason;

/**
 * Scrapped pieces of a production declaration with their mandatory defect reason (Italian: pezzi scartati, causale).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phase_id
 * @property string $production_declaration_id
 * @property string $quantity
 * @property DefectReason $defect_reason
 * @property string|null $note
 */
final class MfgMesScrapDeclaration extends BaseEnterpriseModel
{
    protected $table = 'mfg_mes_scrap_declarations';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'defect_reason' => DefectReason::class,
        ];
    }
}
