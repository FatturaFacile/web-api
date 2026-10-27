<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConformityCertificate extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'conformity_certificates';

    protected $fillable = [
        'tenant_id',
        'construction_site_id',
        'type',
        'certificate_number',
        'inspector_name',
        'certified_at',
        'document_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'certified_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<ConstructionSite, $this>
     */
    public function constructionSite(): BelongsTo
    {
        return $this->belongsTo(ConstructionSite::class, 'construction_site_id');
    }
}
