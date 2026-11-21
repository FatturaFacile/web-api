<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Services;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Quoting\Domain\Exceptions\QuoteAccessDeniedException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

/**
 * Resolves the unguessable public token of a proposal to its quote, enforcing expiry, supersession and the optional PIN,
 * and puts the tenant of the quote in the request context. Every refusal is the same exception: the portal never tells
 * a wrong token from an expired or superseded link.
 */
final class PublicQuoteGate
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function newToken(): string
    {
        return Str::random(48);
    }

    /**
     * @param  bool  $allowClosed  download of the signed documents after acceptance
     */
    public function resolve(string $token, ?string $session = null, bool $allowClosed = false): CpqQuote
    {
        $quote = CpqQuote::withoutGlobalScopes()->where('public_token_hash', self::hashToken($token))->first();

        if (! $quote instanceof CpqQuote || $quote->public_expires_at === null) {
            throw new QuoteAccessDeniedException('This proposal link is not valid.');
        }

        $open = $quote->status->isOpenForCustomer();
        $closed = in_array($quote->status->value, ['accepted', 'converted'], true);

        // The expiry binds every open proposal; only the documents of an already signed quote stay downloadable afterwards.
        if (($open && $quote->public_expires_at->isPast()) || (! $open && ! ($allowClosed && $closed))) {
            throw new QuoteAccessDeniedException('This proposal link is not valid.');
        }

        $tenant = Tenant::query()->find($quote->tenant_id);
        if (! $tenant instanceof Tenant) {
            throw new QuoteAccessDeniedException('This proposal link is not valid.');
        }

        $this->tenantContext->setTenant($tenant);

        if ($quote->public_pin_hash !== null && ! $this->sessionValid($quote, $session)) {
            throw new QuoteAccessDeniedException('A PIN is required to open this proposal.');
        }

        return CpqQuote::query()->findOrFail($quote->id);
    }

    /** Checks the PIN and opens a short-lived session bound to the quote. */
    public function verifyPin(string $token, string $pin): string
    {
        $quote = CpqQuote::withoutGlobalScopes()->where('public_token_hash', self::hashToken($token))->first();

        if (! $quote instanceof CpqQuote || $quote->public_pin_hash === null || ! $quote->status->isOpenForCustomer()
            || ($quote->public_expires_at?->isPast() ?? true)) {
            throw new QuoteAccessDeniedException('This proposal link is not valid.');
        }

        $attemptsKey = 'cpq:pin-attempts:'.$quote->id;
        if ((int) Cache::get($attemptsKey, 0) >= 5) {
            throw new QuoteAccessDeniedException('Too many attempts: try again later.');
        }

        if (! Hash::check($pin, $quote->public_pin_hash)) {
            Cache::put($attemptsKey, (int) Cache::get($attemptsKey, 0) + 1, now()->addMinutes(15));

            throw new QuoteAccessDeniedException('The PIN is not valid.');
        }

        Cache::forget($attemptsKey);
        $session = Str::random(40);
        Cache::put($this->sessionKey($quote, $session), true, now()->addMinutes((int) config('quoting.pin_session_ttl_minutes', 120)));

        return $session;
    }

    private function sessionValid(CpqQuote $quote, ?string $session): bool
    {
        return $session !== null && $session !== '' && Cache::has($this->sessionKey($quote, $session));
    }

    private function sessionKey(CpqQuote $quote, string $session): string
    {
        return 'cpq:pin-session:'.$quote->id.':'.hash('sha256', $session);
    }
}
