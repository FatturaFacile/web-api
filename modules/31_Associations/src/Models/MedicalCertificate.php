<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\CertificateKind;

/**
 * A medical certificate of a person for a discipline, or for any discipline when none is set.
 *
 * @property string $id
 * @property CertificateKind $kind
 * @property CarbonImmutable $expires_on
 */
final class MedicalCertificate extends AssociationRecord
{
    protected $table = 'assoc_medical_certificates';

    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'kind' => CertificateKind::class,
            'issued_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'revoked_at' => 'datetime',
            'physician' => 'encrypted',
            'notes' => 'encrypted',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'member_id');
    }

    public function discipline(): BelongsTo
    {
        return $this->belongsTo(Discipline::class, 'discipline_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInForce(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }
}
