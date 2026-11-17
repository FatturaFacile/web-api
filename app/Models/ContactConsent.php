<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Granular privacy consent of a customer (GDPR for IT/EU, nLPD for CH): one row per purpose, with proof of when
 * and from which channel it was granted or revoked.
 */
class ContactConsent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const PURPOSES = ['marketing', 'profiling', 'operational'];

    public const REGIMES = ['gdpr', 'nlpd'];

    protected $fillable = [
        'tenant_id',
        'contact_id',
        'purpose',
        'regime',
        'granted',
        'granted_at',
        'revoked_at',
        'source',
        'recorded_by_user_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
