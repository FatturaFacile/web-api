<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pieces declared on a phase (Italian: dichiarazione di produzione): good pieces and scrapped pieces, the latter
 * detailed by scrap declaration rows with their defect reason.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $phase_id
 * @property string|null $operator_id
 * @property string $good_quantity
 * @property string $scrap_quantity
 * @property string $idempotency_key
 * @property Carbon $declared_at
 */
final class MfgMesProductionDeclaration extends BaseEnterpriseModel
{
    protected $table = 'mfg_mes_production_declarations';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'good_quantity' => 'decimal:4',
            'scrap_quantity' => 'decimal:4',
            'declared_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<MfgMesScrapDeclaration, $this>
     */
    public function scraps(): HasMany
    {
        return $this->hasMany(MfgMesScrapDeclaration::class, 'production_declaration_id');
    }
}
