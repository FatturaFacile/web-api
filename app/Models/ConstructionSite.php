<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConstructionSite extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'construction_sites';

    protected $fillable = [
        'tenant_id',
        'client_id',
        'code',
        'name',
        'address',
        'sia_norm_reference',
        'npk_code',
        'start_date',
        'estimated_end_date',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'estimated_end_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'client_id');
    }

    /**
     * @return HasMany<ProgressBilling, $this>
     */
    public function progressBillings(): HasMany
    {
        return $this->hasMany(ProgressBilling::class, 'construction_site_id');
    }

    /**
     * @return HasMany<ConformityCertificate, $this>
     */
    public function conformityCertificates(): HasMany
    {
        return $this->hasMany(ConformityCertificate::class, 'construction_site_id');
    }
}
