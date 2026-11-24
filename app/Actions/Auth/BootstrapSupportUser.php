<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\DTOs\Auth\SupportUserBootstrapResult;
use App\Mail\SupportUserBootstrappedMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class BootstrapSupportUser
{
    public const SUPPORT_EMAIL = 'support@fatturafacile.eu';

    public const SUPPORT_NAME = 'Support FatturaFacile';

    /**
     * Check if the support user exists, and create & notify if not present.
     *
     * @throws Throwable If user creation or mail dispatch fails
     */
    public function execute(): SupportUserBootstrapResult
    {
        /** @var User|null $existingUser */
        $existingUser = User::query()->where('email', self::SUPPORT_EMAIL)->first();

        if ($existingUser !== null) {
            Log::info('Support user bootstrap skipped: user already exists.', [
                'email' => self::SUPPORT_EMAIL,
                'user_id' => $existingUser->id,
            ]);

            return new SupportUserBootstrapResult(
                created: false,
                user: $existingUser,
                emailDispatched: false,
                message: 'Support user is already present.',
            );
        }

        // Generate a strong, cryptographically secure 24-character random password
        $rawPassword = Str::password(24, letters: true, numbers: true, symbols: true, spaces: false);

        /** @var User $user */
        /** @var bool $emailDispatched */
        [$user, $emailDispatched] = DB::transaction(function () use ($rawPassword): array {
            $user = User::create([
                'name' => self::SUPPORT_NAME,
                'email' => self::SUPPORT_EMAIL,
                'password' => $rawPassword,
                'is_active' => true,
                'locale' => 'it',
                'email_verified_at' => now(),
            ]);

            try {
                Mail::to($user->email)->send(new SupportUserBootstrappedMail($user, $rawPassword));
                $dispatched = true;

                Log::info('Support user credentials email dispatched successfully.', [
                    'email' => self::SUPPORT_EMAIL,
                    'user_id' => $user->id,
                ]);
            } catch (Throwable $e) {
                Log::error('Failed to send support user bootstrap credentials email.', [
                    'email' => self::SUPPORT_EMAIL,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            return [$user, $dispatched];
        });

        Log::info('Support user successfully created and initialized.', [
            'email' => self::SUPPORT_EMAIL,
            'user_id' => $user->id,
        ]);

        return new SupportUserBootstrapResult(
            created: true,
            user: $user,
            emailDispatched: $emailDispatched,
            message: 'Support user successfully created and credentials email dispatched.',
        );
    }
}
