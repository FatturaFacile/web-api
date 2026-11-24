<?php

declare(strict_types=1);

namespace App\Services\Portal;

use App\Mail\PortalMagicLinkMail;
use App\Mail\PortalOtpMail;
use App\Models\Contact;
use App\Models\PortalAccessToken;
use App\Models\PortalSetting;
use App\Services\Tenancy\TenantContext;
use App\Support\Portal\PortalDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Passwordless sign-in of the customer portal: a single-use magic link (15 minutes) is exchanged for an 8-hour bearer
 * session; when the tenant asks for it, an e-mailed one-time code sits between the two.
 */
final readonly class PortalAuthService
{
    public const int LINK_MINUTES = 15;

    public const int OTP_MINUTES = 10;

    public const int OTP_MAX_ATTEMPTS = 5;

    public const int SESSION_HOURS = 8;

    public function __construct(
        private PortalTokenService $tokens,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Sends a sign-in link when the e-mail belongs to a customer of the portal's tenant. The caller always answers the
     * same way, so the endpoint never tells whether an address is known.
     */
    public function requestLink(PortalSetting $setting, string $email): void
    {
        $tenant = $setting->tenant()->withoutGlobalScopes()->first();

        if ($tenant === null) {
            return;
        }

        $this->tenantContext->setTenant($tenant);

        $contact = Contact::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->whereNull('anonymized_at')
            ->first();

        if ($contact === null) {
            return;
        }

        $issued = $this->tokens->createToken(
            $contact,
            PortalTokenService::LOGIN_TOKEN_TYPE,
            expiresAt: now()->addMinutes(self::LINK_MINUTES),
            isSingleUse: true,
        );

        $query = http_build_query(array_filter(['token' => $issued['token'], 'portal' => $setting->portal_slug]));
        $base = $setting->custom_domain !== null ? 'https://'.$setting->custom_domain : rtrim((string) config('app.frontend_url'), '/');

        Mail::to($contact->email)->send(new PortalMagicLinkMail(
            $contact,
            $base.'/portal/auth/verify?'.$query,
            self::LINK_MINUTES,
            (string) $tenant->name,
        ));
    }

    /**
     * Exchanges the magic link for a session. Without the tenant's second factor it signs in at once; with it, the first
     * call mails the code and answers `otp_required`, and the link stays usable until the code is right.
     *
     * @return array{status: 'otp_required'}|array{status: 'authenticated', token: string, expires_at: string, contact: Contact}
     *
     * @throws ValidationException when the code is wrong
     * @throws TooManyRequestsHttpException when the code was missed too often
     */
    public function verify(string $plaintext, ?string $otp, bool $acceptPrivacy): array
    {
        $login = $this->tokens->resolveToken($plaintext, PortalTokenService::LOGIN_TOKEN_TYPE);
        $contact = $login->contact;
        $setting = PortalSetting::query()->where('tenant_id', $login->tenant_id)->first();

        if ($setting?->require_otp === true) {
            if ($otp === null || $otp === '') {
                $this->issueOtp($login, $contact);

                return ['status' => 'otp_required'];
            }

            $this->checkOtp($login, $otp);
        }

        return DB::transaction(function () use ($login, $contact, $acceptPrivacy): array {
            $login->markUsed();

            if ($acceptPrivacy && $contact->portal_consent_at === null) {
                $contact->forceFill(['portal_consent_at' => now()])->save();
            }

            $session = $this->tokens->createToken(
                $contact,
                PortalTokenService::SESSION_TOKEN_TYPE,
                expiresAt: now()->addHours(self::SESSION_HOURS),
            );

            $this->tokens->logActivity($session['model'], 'portal_login', metadata: [
                'method' => 'magic_link',
                'consent_recorded' => $contact->portal_consent_at !== null,
            ]);

            return [
                'status' => 'authenticated',
                'token' => $session['token'],
                'expires_at' => PortalDate::iso($session['model']->expires_at) ?? '',
                'contact' => $contact,
            ];
        });
    }

    public function logout(PortalAccessToken $session): void
    {
        $session->update(['revoked_at' => now()]);
        $this->tokens->logActivity($session, 'portal_logout');
    }

    private function issueOtp(PortalAccessToken $login, Contact $contact): void
    {
        // A code still alive is not replaced, so repeated calls cannot be used to flood the inbox.
        if ($login->otp_hash !== null && PortalDate::isFuture($login->otp_expires_at)) {
            return;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $login->update([
            'otp_hash' => $this->hashOtp($login, $code),
            'otp_expires_at' => now()->addMinutes(self::OTP_MINUTES),
            'otp_attempts' => 0,
        ]);

        Mail::to($contact->email)->send(new PortalOtpMail($contact, $code, self::OTP_MINUTES, (string) $login->tenant?->name));
    }

    private function checkOtp(PortalAccessToken $login, string $otp): void
    {
        if ($login->otp_hash === null || ! PortalDate::isFuture($login->otp_expires_at)) {
            $login->update(['otp_hash' => null, 'otp_expires_at' => null, 'otp_attempts' => 0]);

            throw ValidationException::withMessages(['otp' => __('portal.errors.otp_invalid')]);
        }

        if ($login->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            $login->update(['revoked_at' => now()]);

            throw new TooManyRequestsHttpException(null, __('portal.errors.otp_locked'));
        }

        if (! hash_equals($login->otp_hash, $this->hashOtp($login, $otp))) {
            $login->increment('otp_attempts');

            throw ValidationException::withMessages(['otp' => __('portal.errors.otp_invalid')]);
        }
    }

    private function hashOtp(PortalAccessToken $login, string $code): string
    {
        return hash_hmac('sha256', $code, $login->id.'|'.(string) config('app.key'));
    }
}
