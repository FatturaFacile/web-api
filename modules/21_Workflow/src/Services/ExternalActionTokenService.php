<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use App\Models\User;
use App\Services\Auth\TwoFactorAuthentication;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Modules\Workflow\Contracts\ExternalActionTokenServiceInterface;
use Modules\Workflow\Exceptions\ApprovalTokenException;
use Modules\Workflow\Models\ApprovalToken;
use Modules\Workflow\Models\WorkflowInstanceStep;

/**
 * Single-use approval links for email, push and Teams/Slack cards. The secret is shown once and stored hashed.
 *
 * A link never decides by itself: it opens a confirmation page ({@see peek()}, no side effect) and the decision is taken
 * only when the approver confirms ({@see verifyAndConsumeToken()}). For an amount above
 * `config('workflow.token.reauth_threshold')` the confirmation also needs the approver to sign in again with their
 * password or, when they have two-factor authentication, a verification code. A used, expired or unknown token answers
 * 410, another user's token 403, and neither changes anything.
 */
final class ExternalActionTokenService implements ExternalActionTokenServiceInterface
{
    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly ApprovalGuardService $guard,
        private readonly TwoFactorAuthentication $twoFactor
    ) {}

    /**
     * {@inheritDoc}
     */
    public function generateActionToken(WorkflowInstanceStep $step, User $user, string $actionType, string $channel = 'email'): string
    {
        $rawSecret = Str::random(64);
        $expiresAt = now()->addHours(max(1, (int) config('workflow.token.ttl_hours', 72)));
        $amount = $step->instance !== null ? $this->guard->amountOf($step->instance) : null;
        $threshold = (float) config('workflow.token.reauth_threshold', 5000);

        ApprovalToken::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $step->tenant_id,
            'workflow_instance_step_id' => $step->id,
            'token_hash' => hash('sha256', $rawSecret),
            'action_type' => strtolower($actionType),
            'authorized_user_id' => $user->id,
            'expires_at' => $expiresAt,
            'used_at' => null,
            'requires_reauth' => $amount !== null && $amount > $threshold,
            'amount' => $amount,
            'channel' => $channel,
        ]);

        return URL::signedRoute('workflows.remote-approval', [
            'token' => $rawSecret,
            'action' => strtolower($actionType),
        ], $expiresAt);
    }

    /**
     * The link of the confirmation page of the web application (`/approve/{token}`), for emails and cards.
     */
    public function confirmationUrl(string $rawSecret, string $actionType): string
    {
        return rtrim((string) config('app.frontend_url'), '/').'/approve/'.$rawSecret.'?action='.strtolower($actionType);
    }

    /**
     * Reads a usable token without consuming it: what the confirmation page shows. The signed-in user, when there is one,
     * must be the user the token was issued to.
     *
     * @throws ApprovalTokenException
     */
    public function peek(string $rawToken, ?User $signedIn = null, ?string $actionType = null): ApprovalToken
    {
        $token = ApprovalToken::query()->where('token_hash', hash('sha256', $rawToken))->first();

        if ($token === null) {
            throw ApprovalTokenException::invalid();
        }

        $this->assertUsable($token, $signedIn, $actionType);

        return $token;
    }

    /**
     * {@inheritDoc}
     *
     * @throws ApprovalTokenException
     */
    public function verifyAndConsumeToken(string $rawToken, string $actionType, ?User $signedIn = null, ?string $password = null, ?string $otp = null): ApprovalToken
    {
        $tokenHash = hash('sha256', $rawToken);

        return DB::transaction(function () use ($tokenHash, $actionType, $signedIn, $password, $otp): ApprovalToken {
            $token = ApprovalToken::where('token_hash', $tokenHash)->lockForUpdate()->first();

            if ($token === null) {
                throw ApprovalTokenException::invalid();
            }

            $this->assertUsable($token, $signedIn, $actionType);

            if ($token->requires_reauth) {
                $this->assertReauthenticated($token, $signedIn, $password, $otp);
            }

            $token->update(['used_at' => now()]);

            return $token;
        });
    }

    /**
     * @throws ApprovalTokenException
     */
    private function assertUsable(ApprovalToken $token, ?User $signedIn, ?string $actionType): void
    {
        if ($token->used_at !== null) {
            throw ApprovalTokenException::used();
        }

        if (now()->isAfter($token->expires_at)) {
            throw ApprovalTokenException::expired();
        }

        if ($signedIn !== null && (string) $signedIn->id !== (string) $token->authorized_user_id) {
            throw ApprovalTokenException::wrongUser();
        }

        if ($actionType !== null && strtolower((string) $token->action_type) !== strtolower($actionType)) {
            throw ApprovalTokenException::wrongAction((string) $token->action_type, $actionType);
        }
    }

    /**
     * An amount above the threshold needs the approver signed in and proven again: a verification code when the user has
     * two-factor authentication, the password otherwise.
     *
     * @throws ApprovalTokenException
     */
    private function assertReauthenticated(ApprovalToken $token, ?User $signedIn, ?string $password, ?string $otp): void
    {
        if ($signedIn === null || (string) $signedIn->id !== (string) $token->authorized_user_id) {
            throw ApprovalTokenException::reauthRequired();
        }

        $proven = $this->twoFactor->isEnabled($signedIn)
            ? ($otp !== null && $otp !== '' && $this->twoFactor->verifyCode($signedIn, $otp, null) !== null)
            : ($password !== null && $password !== '' && Hash::check($password, (string) $signedIn->password));

        if (! $proven) {
            throw ApprovalTokenException::reauthFailed();
        }
    }
}
