<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An e-signature request for one document version: signers, order, status and the hash of the signed text.
 *
 * @property string $contract_kind
 * @property string $contract_id
 * @property string $document_version_id
 * @property string $provider
 * @property string|null $provider_reference
 * @property string $status
 * @property string $order_mode
 * @property string $document_hash
 * @property Carbon|null $expires_on
 * @property Carbon|null $sent_at
 * @property Carbon|null $completed_at
 * @property string|null $created_by
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
