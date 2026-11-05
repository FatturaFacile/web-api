<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use Illuminate\Support\Facades\DB;

class ResendVerificationEmail
{
    public function __construct(
        private readonly RegisterUser $registration,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * Unknown or already verified addresses are ignored silently, so the caller learns nothing about them.
     */
    public function execute(string $email): void
    {
        $user = User::query()->where('email', $email)->whereNull('email_verified_at')->first();

        if ($user === null) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $this->registration->sendVerification($user);
            $this->audit->record(RegistrationAudit::VERIFICATION_RESENT, $user->id);
        });
    }
}
