<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Mail\EmailAlreadyRegisteredMail;
use App\Mail\VerifyEmailMail;
use App\Models\TenantAccessRequest;
use App\Models\User;
use App\Services\Auth\EmailVerificationTokens;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\TaxIdentifier;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Self-registration. The caller always gets the same answer, so it cannot learn whether an email address or a company
 * is already known: the mailbox owner is the only one told what happened.
 */
class RegisterUser
{
    public function __construct(
        private readonly EmailVerificationTokens $tokens,
        private readonly RegistrationAudit $audit,
    ) {}

    /**
     * @param  array{first_name: string, last_name: string, email: string, password: string, company_name: string, tax_id: string, country: string}  $data
     */
    public function execute(array $data): void
    {
        // Hashed on every path: the response time must not reveal whether the address is known.
        $data['password'] = Hash::make($data['password']);

        $existing = User::withTrashed()->where('email', $data['email'])->first();

        if ($existing !== null && ($existing->email_verified_at !== null || $existing->trashed())) {
            $this->notifyExistingAccount($existing);

            return;
        }

        try {
            $this->store($existing, $data);
        } catch (UniqueConstraintViolationException) {
            // A concurrent registration took the address first.
            $winner = User::withTrashed()->where('email', $data['email'])->first();

            if ($winner !== null) {
                $this->notifyExistingAccount($winner);
            }
        }
    }

    /**
     * A second registration on a never verified address replaces the first one and rotates the token: whoever registered
     * someone else's address first cannot take over the account once its owner confirms it.
     *
     * @param  array{first_name: string, last_name: string, email: string, password: string, company_name: string, tax_id: string, country: string}  $data
     */
    private function store(?User $user, array $data): void
    {
        DB::transaction(function () use ($user, $data): void {
            $now = Carbon::now();
            $user ??= new User(['email' => $data['email']]);
            $isNew = ! $user->exists;

            $user->fill([
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'password' => $data['password'],
                'locale' => App::getLocale(),
                'is_active' => true,
            ])->forceFill([
                'terms_accepted_at' => $now,
                'privacy_accepted_at' => $now,
            ])->save();

            TenantAccessRequest::updateOrCreate(['user_id' => $user->id], [
                'tenant_id' => TaxIdentifier::findTenant($data['tax_id'])?->id,
                'requested_company_name' => $data['company_name'],
                'requested_tax_id' => $data['tax_id'],
                'requested_country' => $data['country'],
                'status' => TenantAccessRequest::PENDING,
            ]);

            if ($isNew) {
                $this->audit->record(RegistrationAudit::USER_REGISTERED, $user->id);
            }

            $this->sendVerification($user);
            $this->audit->record(RegistrationAudit::VERIFICATION_SENT, $user->id);
        });
    }

    public function sendVerification(User $user): void
    {
        $token = $this->tokens->issue($user);

        Mail::to($user->email)
            ->locale($user->locale)
            ->queue(new VerifyEmailMail($user, $this->tokens->link($token), $user->email_verification_expires_at));
    }

    private function notifyExistingAccount(User $user): void
    {
        Mail::to($user->email)->locale($user->locale)->queue(new EmailAlreadyRegisteredMail($user));
    }
}
