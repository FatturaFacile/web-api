<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Workflow\Contracts\ExternalActionTokenServiceInterface;
use Modules\Workflow\Models\ApprovalToken;
use Modules\Workflow\Models\WorkflowInstanceStep;

final class ExternalActionTokenService implements ExternalActionTokenServiceInterface
{
    public function generateActionToken(WorkflowInstanceStep $step, User $user, string $actionType): string
    {
        $rawSecret = Str::random(64);
        $tokenHash = hash('sha256', $rawSecret);
        $expiresAt = now()->addHours(48);

        ApprovalToken::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $step->tenant_id,
            'workflow_instance_step_id' => $step->id,
            'token_hash' => $tokenHash,
            'action_type' => strtolower($actionType),
            'authorized_user_id' => $user->id,
            'expires_at' => $expiresAt,
            'used_at' => null,
        ]);

        return URL::signedRoute('workflows.remote-approval', [
            'token' => $rawSecret,
            'action' => strtolower($actionType),
        ], $expiresAt);
    }

    public function verifyAndConsumeToken(string $rawToken, string $actionType): ApprovalToken
    {
        $tokenHash = hash('sha256', $rawToken);

        return DB::transaction(function () use ($tokenHash, $actionType) {
            $token = ApprovalToken::where('token_hash', $tokenHash)->lockForUpdate()->first();

            if ($token === null) {
                throw new InvalidArgumentException('Invalid remote approval token.');
            }

            if ($token->used_at !== null) {
                throw new InvalidArgumentException('Remote approval token has already been consumed.');
            }

            if (now()->isAfter($token->expires_at)) {
                throw new InvalidArgumentException('Remote approval token has expired.');
            }

            if (strtolower($token->action_type) !== strtolower($actionType)) {
                throw new InvalidArgumentException("Token was issued for action [{$token->action_type}], cannot execute [{$actionType}].");
            }

            $token->update(['used_at' => now()]);

            return $token;
        });
    }
}
