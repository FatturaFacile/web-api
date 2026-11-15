<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class LeadSource extends BaseEnterpriseModel
{
    public const array TYPES = ['web_form', 'wordpress', 'webflow', 'meta_lead_ads', 'linkedin_lead_gen', 'manual', 'csv'];

    protected $table = 'crm_lead_sources';

    /**
     * Credentials are write-only: they are shown once by the API and never serialized afterwards.
     *
     * @var list<string>
     */
    protected $hidden = ['webhook_secret', 'api_key_hash'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'webhook_secret' => 'encrypted',
            'allowed_origins' => 'array',
            'field_mapping' => 'array',
            'last_received_at' => 'datetime',
        ];
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'lead_source_id');
    }

    public function verifyApiKey(string $apiKey): bool
    {
        if (empty($this->api_key_hash) || $apiKey === '') {
            return false;
        }

        return hash_equals((string) $this->api_key_hash, hash('sha256', $apiKey));
    }
}
