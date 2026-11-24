<?php

declare(strict_types=1);

namespace Modules\Copilot\Commands;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Copilot\Enums\ActionStatus;
use Modules\Copilot\Models\CopilotMessage;
use Modules\Copilot\Services\CopilotAuditor;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Cancels a staged action. Only the owner of the conversation can; rejecting twice is harmless, rejecting an action that
 * already ran is a conflict.
 */
final readonly class RejectCopilotActionCommand
{
    public function __construct(private CopilotAuditor $auditor) {}

    /**
     * @throws ModelNotFoundException
     * @throws ConflictHttpException
     */
    public function execute(User $user, string $tenantId, string $messageId, ?string $reason = null): CopilotMessage
    {
        $message = DB::transaction(function () use ($user, $tenantId, $messageId): CopilotMessage {
            $message = CopilotMessage::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($messageId)
                ->whereNotNull('action_status')
                ->whereHas('conversation', static fn ($query) => $query->where('user_id', (string) $user->id))
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                throw (new ModelNotFoundException)->setModel(CopilotMessage::class, [$messageId]);
            }

            if ($message->action_status === ActionStatus::Rejected) {
                return $message;
            }

            if ($message->action_status !== ActionStatus::Pending) {
                throw new ConflictHttpException('This action is '.($message->action_status->value).' and cannot be rejected.');
            }

            $message->forceFill([
                'action_status' => ActionStatus::Rejected,
                'proposed_action_json' => [...(array) $message->proposed_action_json, 'status' => ActionStatus::Rejected->value, 'rejected_at' => now()->toIso8601String()],
            ])->save();

            return $message;
        });

        if ($message->wasChanged('action_status')) {
            $this->auditor->record('action_rejected', $message, ['tool' => $message->proposed_action_json['tool'] ?? null, 'reason' => $reason]);
        }

        return $message;
    }
}
