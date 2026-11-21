<?php

declare(strict_types=1);

namespace Modules\ShiftPlanning\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\ShiftPlanning\Domain\Enums\SwapStatus;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftConflictException;
use Modules\ShiftPlanning\Domain\Exceptions\ShiftPlanningException;

/**
 * Aggregate root of a peer swap: the requester offers a shift to one colleague (or to every qualified colleague when the
 * target peer is null), optionally against a shift of the colleague. The colleague accepts, then the manager approves.
 *
 * @property string $requester_employee_id
 * @property string|null $target_peer_employee_id
 * @property string $shift_assignment_id
 * @property string|null $target_shift_assignment_id
 * @property SwapStatus $status
 * @property CarbonImmutable|null $peer_responded_at
 * @property string|null $reviewed_by_user_id
 * @property CarbonImmutable|null $reviewed_at
 * @property string|null $rejection_reason
 */
final class ShiftSwapRequest extends BaseEnterpriseModel
{
    protected $table = 'roster_shift_swap_requests';

    protected function casts(): array
    {
        return [
            'status' => SwapStatus::class,
            'peer_responded_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ScheduledShiftAssignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ScheduledShiftAssignment::class, 'shift_assignment_id');
    }

    /**
     * @return BelongsTo<ScheduledShiftAssignment, $this>
     */
    public function targetAssignment(): BelongsTo
    {
        return $this->belongsTo(ScheduledShiftAssignment::class, 'target_shift_assignment_id');
    }

    public function isBroadcast(): bool
    {
        return $this->target_peer_employee_id === null;
    }

    /**
     * The colleague takes the swap. For a broadcast the first qualified colleague becomes the target peer.
     */
    public function acceptByPeer(string $peerEmployeeId): void
    {
        $this->assertStatus(SwapStatus::PendingPeerAcceptance);

        if ($peerEmployeeId === $this->requester_employee_id) {
            throw new ShiftPlanningException('A swap cannot be accepted by the employee who requested it.');
        }

        if (! $this->isBroadcast() && $this->target_peer_employee_id !== $peerEmployeeId) {
            throw new ShiftPlanningException('This swap was offered to another colleague.');
        }

        $this->update([
            'target_peer_employee_id' => $peerEmployeeId,
            'status' => SwapStatus::PendingManagerApproval,
            'peer_responded_at' => CarbonImmutable::now(),
        ]);
    }

    public function rejectByPeer(): void
    {
        $this->assertStatus(SwapStatus::PendingPeerAcceptance);
        $this->update(['status' => SwapStatus::Rejected, 'peer_responded_at' => CarbonImmutable::now(), 'rejection_reason' => 'peer_declined']);
    }

    public function approve(string $userId): void
    {
        $this->assertStatus(SwapStatus::PendingManagerApproval);
        $this->update(['status' => SwapStatus::Approved, 'reviewed_by_user_id' => $userId, 'reviewed_at' => CarbonImmutable::now()]);
    }

    public function rejectByManager(string $userId, string $reason): void
    {
        $this->assertStatus(SwapStatus::PendingManagerApproval);
        $this->update(['status' => SwapStatus::Rejected, 'reviewed_by_user_id' => $userId, 'reviewed_at' => CarbonImmutable::now(), 'rejection_reason' => $reason]);
    }

    private function assertStatus(SwapStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new ShiftConflictException("The swap is {$this->status->value}, not {$expected->value}.");
        }
    }
}
