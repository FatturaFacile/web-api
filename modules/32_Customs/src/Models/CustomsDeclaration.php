<?php

declare(strict_types=1);

namespace Modules\Customs\Models;

use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Modules\Customs\Enums\CustomsRegime;
use Modules\Customs\Enums\DeclarationStatus;
use Modules\Customs\Enums\DeclarationType;
use Modules\Customs\Enums\TransitDocumentType;

/**
 * @property DeclarationType $declaration_type
 * @property DeclarationStatus $status
 * @property CustomsRegime $customs_regime
 * @property TransitDocumentType $transit_document_type
 * @property Carbon|null $transit_deadline_on
 * @property Carbon|null $transit_discharged_at
 */
final class CustomsDeclaration extends CustomsRecord
{
    use SoftDeletes;

    protected $table = 'customs_goods_declarations';

    protected function casts(): array
    {
        return [
            'declaration_type' => DeclarationType::class,
            'customs_regime' => CustomsRegime::class,
            'transit_document_type' => TransitDocumentType::class,
            'transit_deadline_on' => 'date',
            'transit_discharged_at' => 'datetime',
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
     * The invoices this declaration clears: an export declaration with an assessment decision is the proof of export of
     * each of them.
     *
     * @return BelongsToMany<Invoice, $this>
     */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(Invoice::class, 'customs_declaration_invoices', 'declaration_id', 'invoice_id')
            ->withPivot('tenant_id')
            ->withTimestamps();
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
