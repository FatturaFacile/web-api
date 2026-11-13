<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Contact extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $attributes = [
        'type' => 'customer',
        'country' => 'IT',
    ];

    protected $fillable = [
        'type',
        'legal_type',
        'country',
        'company_name',
        'first_name',
        'last_name',
        'email',
        'phone',
        'vat_number',
        'fiscal_code',
        'uid_number',
        'sdi_code',
        'pec',
        'iban',
        'qr_iban',
        'payment_terms_days',
        'price_list_id',
        'payment_term_id',
        'document_language',
        'processing_restricted_at',
        'anonymized_at',
    ];

    protected function casts(): array
    {
        return [
            'processing_restricted_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /**
     * Calculated grid columns as portable SQL (same on SQLite and PostgreSQL), so they filter, sort, group and list
     * their values in the database like real columns. Reachable from related grids as `contact.name`.
     *
     * @return array<string, string>
     */
    public function gridColumns(): array
    {
        return [
            'tax_id' => "COALESCE(NULLIF(contacts.vat_number, ''), NULLIF(contacts.fiscal_code, ''), NULLIF(contacts.uid_number, ''))",
            'name' => "COALESCE(NULLIF(contacts.company_name, ''), NULLIF(TRIM(COALESCE(contacts.first_name, '') || ' ' || COALESCE(contacts.last_name, '')), ''), '-')",
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function type(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => empty($value) ? 'customer' : $value,
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value !== null ? strtolower(trim($value)) : null,
        );
    }

    /**
     * @return HasMany<ContactAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * @return HasMany<SalesOrder, $this>
     */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    /**
     * @return HasMany<DeliveryNote, $this>
     */
    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class);
    }

    /**
     * @return HasMany<ServiceReport, $this>
     */
    public function serviceReports(): HasMany
    {
        return $this->hasMany(ServiceReport::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
