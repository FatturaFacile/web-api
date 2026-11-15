<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An e-signature request for one document version: signers, order, status and the hash of the signed text.
 */
final class ContractSignatureRequest extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['draft', 'sent', 'completed', 'declined', 'cancelled', 'expired'];

    protected $table = 'contract_signature_requests';

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'sent_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    /**
     * @return HasMany<ContractSigner, $this>
     */
    public function signers(): HasMany
    {
        return $this->hasMany(ContractSigner::class, 'request_id')->orderBy('position');
    }

    /**
     * @return HasMany<ContractSignatureEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ContractSignatureEvent::class, 'request_id')->orderBy('created_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<ContractDocumentVersion, $this>
     */
    public function documentVersion(): BelongsTo
    {
        return $this->belongsTo(ContractDocumentVersion::class, 'document_version_id');
    }
}
