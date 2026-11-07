<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $attributes = [
        'status' => 'active',
        'country' => 'IT',
        'default_currency' => 'EUR',
    ];

    protected $fillable = [
        'name',
        'legal_name',
        'fiscal_code',
        'vat_number',
        'uid_number',
        'country',
        'default_currency',
        'iban',
        'qr_iban',
        'sdi_code',
        'pec_email',
        'settings',
        'id',
        'status',
        'sdi_channel',
        'sdi_environment',
        'sdi_pec_smtp_host',
        'sdi_pec_smtp_port',
        'sdi_pec_imap_host',
        'sdi_pec_imap_port',
        'sdi_pec_username',
        'sdi_pec_password',
        'sdi_pec_address',
        'sdi_pec_first_sent_at',
        'sdi_pec_last_uid',
        'sdi_api_credentials',
        'sdi_webhook_secret',
        'sdi_sign_outgoing',
        'sdi_last_poll_at',
        'ade_telematic_address_registered',
        'ade_conservation_agreement_at',
        'document_quota_points',
    ];

    /**
     * Mailbox and gateway credentials never leave the API, not even towards the administrators of the tenant.
     *
     * @var list<string>
     */
    protected $hidden = [
        'sdi_pec_password',
        'sdi_api_credentials',
        'sdi_webhook_secret',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'is_maintenance' => 'boolean',
            'sdi_pec_password' => 'encrypted',
            'sdi_api_credentials' => 'encrypted:array',
            'sdi_webhook_secret' => 'encrypted',
            'sdi_sign_outgoing' => 'boolean',
            'sdi_pec_first_sent_at' => 'datetime',
            'sdi_last_poll_at' => 'datetime',
            'ade_telematic_address_registered' => 'boolean',
            'ade_conservation_agreement_at' => 'date',
            'document_quota_points' => 'decimal:1',
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')
            ->withPivot(['id', 'role', 'permissions'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Contact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }
}
