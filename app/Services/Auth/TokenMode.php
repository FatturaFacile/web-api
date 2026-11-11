<?php

declare(strict_types=1);

namespace App\Services\Auth;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The optional bearer-token mode of the user authentication. Users sign in with the session cookie; a Sanctum token is
 * issued, and accepted as the credential of a user, only when `auth.token_mode.enabled` is on AND the client asks for it
 * with `token_mode: true` on the login (or on the SSO callback). With the flag off the parameter is a 422.
 */
final class TokenMode
{
    public const string PARAMETER = 'token_mode';

    public function enabled(): bool
    {
        return (bool) config('auth.token_mode.enabled');
    }

    /**
     * Whether the client asked for a token, after checking that it may.
     *
     * @throws ValidationException when a token is requested and the token mode is off
     */
    public function resolve(Request $request): bool
    {
        if (! $request->has(self::PARAMETER) || ! $request->boolean(self::PARAMETER)) {
            return false;
        }

        if (! $this->enabled()) {
            throw ValidationException::withMessages([
                self::PARAMETER => [__('enterprise_auth.errors.token_mode_disabled')],
            ]);
        }

        return true;
    }
}
