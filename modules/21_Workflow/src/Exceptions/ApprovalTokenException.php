<?php

declare(strict_types=1);

namespace Modules\Workflow\Exceptions;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * A one-click approval token that cannot be used. It is an `InvalidArgumentException` for the callers that use the token
 * service in code, and renders itself for the API: 410 (gone) for an unknown, used or expired token, 403 for a token of
 * another user or a failed re-authentication. Nothing has changed when it is thrown.
 */
final class ApprovalTokenException extends InvalidArgumentException
{
    /**
     * @param  string  $reason  one of invalid, used, expired, wrong_user, wrong_action, confirm_required, reauth_required, reauth_failed
     */
    private function __construct(string $message, public readonly string $reason, public readonly int $status)
    {
        parent::__construct($message);
    }

    /**
     * No token matches the secret.
     */
    public static function invalid(): self
    {
        return new self('Invalid remote approval token.', 'invalid', 410);
    }

    /**
     * The token was already consumed.
     */
    public static function used(): self
    {
        return new self('Remote approval token has already been consumed.', 'used', 410);
    }

    /**
     * The token is past its expiry.
     */
    public static function expired(): self
    {
        return new self('Remote approval token has expired.', 'expired', 410);
    }

    /**
     * The token was issued to another user than the one signed in.
     */
    public static function wrongUser(): self
    {
        return new self('Remote approval token was issued to another user.', 'wrong_user', 403);
    }

    /**
     * The token carries another action than the one asked for.
     */
    public static function wrongAction(string $issued, string $asked): self
    {
        return new self("Token was issued for action [{$issued}], cannot execute [{$asked}].", 'wrong_action', 403);
    }

    /**
     * The decision was not confirmed on the confirmation page.
     */
    public static function confirmationRequired(): self
    {
        return new self('The decision must be confirmed.', 'confirm_required', 422);
    }

    /**
     * The amount needs a signed-in approver and none is.
     */
    public static function reauthRequired(): self
    {
        return new self('Re-authentication is required for this amount.', 'reauth_required', 403);
    }

    /**
     * The password or the verification code is wrong.
     */
    public static function reauthFailed(): self
    {
        return new self('Re-authentication failed.', 'reauth_failed', 403);
    }

    /**
     * The API answer.
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => __('workflow_errors.token.'.$this->reason),
            'code' => $this->reason,
        ], $this->status);
    }
}
