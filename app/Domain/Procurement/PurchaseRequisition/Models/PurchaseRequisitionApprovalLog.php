<?php

declare(strict_types=1);

namespace App\Domain\Procurement\PurchaseRequisition\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only, strictly immutable approval and budget audit log entry for Mod 044 Purchase Requisitions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $purchase_requisition_id
 * @property int $approval_step_level
 * @property string|null $approver_user_id
 * @property string $action
 * @property string $channel
 * @property string|null $comment
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property CarbonImmutable $acted_at
 * @property CarbonImmutable|null $created_at
 * @property-read PurchaseRequisition|null $requisition
 * @property-read User|null $approver
 */
final class PurchaseRequisitionApprovalLog extends Model
{
    use BelongsToTenant, HasUuidV7;

    protected $table = 'purchase_requisition_approval_logs';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        self::creating(static function (PurchaseRequisitionApprovalLog $log): void {
            if ($log->created_at === null) {
                $log->created_at = CarbonImmutable::now('UTC');
            }

            if ($log->acted_at === null) {
                $log->acted_at = CarbonImmutable::now('UTC');
            }
        });

        self::updating(static function (): never {
            throw new LogicException('Purchase requisition approval logs are strictly immutable and cannot be updated.');
        });

        self::deleting(static function (): never {
            throw new LogicException('Purchase requisition approval logs are strictly immutable and cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'approval_step_level' => 'integer',
            'metadata' => 'array',
            'acted_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
