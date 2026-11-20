<?php

declare(strict_types=1);

namespace Modules\Copilot\Commands;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Copilot\Contracts\MutatingActionTool;
use Modules\Copilot\DTOs\ToolContext;
use Modules\Copilot\Enums\ActionStatus;
use Modules\Copilot\Models\CopilotMessage;
use Modules\Copilot\Services\CopilotAuditor;
use Modules\Copilot\Services\CopilotToolRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * Runs an action the user confirmed. Only the owner of the conversation can confirm, with the permission the tool needs
 * today (checked again, not at proposal time). The user may change only the fields the card declared editable; the changed
 * arguments are validated again. A confirmed card moves pending -> confirmed under a row lock, so a double click or a retry
 * can never execute twice: confirming an already executed card returns it unchanged.
 */
final readonly class ConfirmAndExecuteCopilotActionCommand
{
    public function __construct(
        private CopilotToolRegistry $registry,
        private CopilotAuditor $auditor,
    ) {}

    /**
     * @param  array<string, mixed>  $overrides  new values by dotted argument path, e.g. items.0.quantity
     *
     * @throws ModelNotFoundException when the message is not an own pending-action message of the tenant
     * @throws AccessDeniedHttpException
     * @throws ConflictHttpException
     * @throws ValidationException
     */
    public function execute(User $user, string $tenantId, string $messageId, array $overrides = []): CopilotMessage
    {
        $message = $this->ownMessage($user, $tenantId, $messageId);

        if ($message->action_status === ActionStatus::Executed) {
            return $message;
        }

        if ($message->action_status !== ActionStatus::Pending) {
            throw new ConflictHttpException('This action is '.($message->action_status->value).' and cannot be confirmed.');
        }

        /** @var array<string, mixed> $proposal */
        $proposal = $message->proposed_action_json;
        $context = new ToolContext($user, $tenantId, 'en');
        $tool = $this->registry->find((string) $proposal['tool'], $context);

        if (! $tool instanceof MutatingActionTool) {
            throw new AccessDeniedHttpException('You are not allowed to run this action.');
        }

        $arguments = $this->applyOverrides($proposal, $overrides);
        $arguments = $this->registry->validate($tool, $context, $arguments);

        DB::transaction(function () use ($messageId, $tenantId): void {
            $locked = CopilotMessage::query()->where('tenant_id', $tenantId)->whereKey($messageId)->lockForUpdate()->firstOrFail();

            if ($locked->action_status !== ActionStatus::Pending) {
                throw new ConflictHttpException('This action is already being handled.');
            }

            $locked->forceFill(['action_status' => ActionStatus::Confirmed])->save();
        });

        $this->auditor->record('action_confirmed', $message, ['tool' => $tool->name(), 'overridden_fields' => array_keys($overrides)]);

        try {
            $result = $tool->execute($context, $arguments);
        } catch (Throwable $exception) {
            $message->forceFill([
                'action_status' => ActionStatus::Failed,
                'proposed_action_json' => [...$proposal, 'status' => ActionStatus::Failed->value, 'arguments' => $arguments, 'error' => mb_substr($exception->getMessage(), 0, 500)],
            ])->save();
            $this->auditor->record('action_failed', $message, ['tool' => $tool->name(), 'error' => mb_substr($exception->getMessage(), 0, 500)]);

            return $message;
        }

        $message->forceFill([
            'action_status' => ActionStatus::Executed,
            'proposed_action_json' => [...$proposal, 'status' => ActionStatus::Executed->value, 'arguments' => $arguments, 'result' => $result, 'executed_at' => now()->toIso8601String()],
        ])->save();
        $this->auditor->record('action_executed', $message, ['tool' => $tool->name(), 'result' => $result]);

        return $message;
    }

    private function ownMessage(User $user, string $tenantId, string $messageId): CopilotMessage
    {
        $message = CopilotMessage::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($messageId)
            ->whereNotNull('action_status')
            ->whereHas('conversation', static fn ($query) => $query->where('user_id', (string) $user->id))
            ->first();

        if ($message === null) {
            throw (new ModelNotFoundException)->setModel(CopilotMessage::class, [$messageId]);
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function applyOverrides(array $proposal, array $overrides): array
    {
        $arguments = (array) $proposal['arguments'];
        $editable = (array) ($proposal['editable_fields'] ?? []);

        foreach ($overrides as $path => $value) {
            if (! in_array((string) $path, $editable, true)) {
                throw ValidationException::withMessages(['overrides' => ["The field [{$path}] cannot be changed on this action."]]);
            }

            Arr::set($arguments, (string) $path, $value);
        }

        return $arguments;
    }
}
