<?php

declare(strict_types=1);

namespace App\Http\Resources\Audit;

use App\Models\AuditLog;
use App\Models\AuditWormBatch;
use Illuminate\Http\Request;

/**
 * One audit record with its decrypted before/after and its cryptographic proof. The values are built by the reader (who
 * also writes the AUDIT_LOG_INSPECTED record) and handed in, so this class never decrypts anything itself.
 *
 * @mixin AuditLog
 */
class AuditLogDetailResource extends AuditLogResource
{
    /**
     * @param  array{old: array<string, mixed>|null, new: array<string, mixed>|null}  $values
     */
    public function __construct(AuditLog $resource, private readonly array $values, private readonly ?AuditWormBatch $batch)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'user_agent' => $this->user_agent,
            'old_values' => $this->values['old'],
            'new_values' => $this->values['new'],
            'proof' => [
                'sequence_number' => $this->sequence_number,
                'previous_hash' => $this->previous_hash,
                'current_hash' => $this->current_hash,
                'hmac_signature' => $this->hmac_signature,
                'request_id' => $this->request_id,
                'worm_batch' => $this->batch === null ? null : (new AuditWormBatchResource($this->batch))->toArray($request),
            ],
        ]);
    }
}
