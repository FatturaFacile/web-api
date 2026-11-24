<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Customs\Enums\DossierStatus;

final class CustomsDossier extends CustomsRecord
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => DossierStatus::class,
            'gross_weight_kg' => 'decimal:3',
            'volume_m3' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_contact_id');
    }

    /**
     * @return BelongsTo<CustomsConsolidation, $this>
     */
    public function consolidation(): BelongsTo
    {
        return $this->belongsTo(CustomsConsolidation::class, 'consolidation_id');
    }

    /**
     * @return HasMany<CustomsDossierCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(CustomsDossierCharge::class, 'dossier_id');
    }

    /**
     * @return HasMany<CustomsQuote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(CustomsQuote::class, 'dossier_id');
    }

    /**
     * @return HasMany<CustomsDossierDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CustomsDossierDocument::class, 'dossier_id');
    }

    /**
     * @return HasMany<CustomsDossierDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CustomsDossierDelivery::class, 'dossier_id');
    }

    /**
     * @return HasMany<CustomsDeclaration, $this>
     */
    public function declarations(): HasMany
    {
        return $this->hasMany(CustomsDeclaration::class, 'dossier_id');
    }
}
