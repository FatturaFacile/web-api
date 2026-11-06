<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use App\Models\Contact;
use App\Models\DeliveryNote;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Customs\Enums\DeclarationStatus;
use Modules\Customs\Enums\DeclarationType;

final class CustomsDeclaration extends CustomsRecord
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'declaration_type' => DeclarationType::class,
            'status' => DeclarationStatus::class,
            'transport_in_container' => 'boolean',
            'container_numbers' => 'array',
            'total_gross_mass' => 'decimal:3',
            'total_net_mass' => 'decimal:3',
            'total_statistical_value' => 'decimal:2',
            'total_vat_value' => 'decimal:2',
            'submitted_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declared_on' => 'date',
        ];
    }

    /**
     * @return HasMany<CustomsDeclarationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CustomsDeclarationItem::class, 'declaration_id')->orderBy('line_number');
    }

    /**
     * @return BelongsTo<DeliveryNote, $this>
     */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function consignor(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'consignor_contact_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function consignee(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'consignee_contact_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function importer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'importer_contact_id');
    }

    /**
     * @return BelongsTo<CustomsDossier, $this>
     */
    public function dossier(): BelongsTo
    {
        return $this->belongsTo(CustomsDossier::class, 'dossier_id');
    }

    /**
     * @return HasMany<CustomsGatewayMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(CustomsGatewayMessage::class, 'declaration_id');
    }
}
