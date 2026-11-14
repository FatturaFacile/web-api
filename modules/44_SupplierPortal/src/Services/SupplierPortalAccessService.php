<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Modules\Procurement\Models\Supplier;
use Modules\SupplierPortal\Mail\SupplierPortalAccessMail;
use Modules\SupplierPortal\Models\SupplierOnboarding;

/**
 * Personal links of the supplier portal: magic links of the customer portal with a dedicated token type, bound to the
 * contact of the supplier. The token itself is only ever in the mail.
 */
final class SupplierPortalAccessService
{
    public const string TokenType = 'supplier_portal';

    public function __construct(private readonly PortalTokenService $tokens, private readonly SupplierPortalLedger $ledger) {}

    /**
     * The contact a supplier logs in with: its own contact, or the contact that shares its id.
     */
    public function contactOf(Supplier $supplier): Contact
    {
        $contact = $supplier->contact_id !== null ? Contact::query()->find($supplier->contact_id) : null;

        return $contact ?? Contact::query()->find($supplier->id) ?? throw new BusinessRuleException('The supplier has no contact to send the portal link to.');
    }

    /**
     * Revokes the earlier links of the supplier and mails a new one.
     */
    public function issueAccess(SupplierOnboarding $onboarding, ?string $email = null): void
    {
        $supplier = Supplier::query()->findOrFail($onboarding->supplier_id);
        $contact = $this->contactOf($supplier);
        $recipient = $email ?? $onboarding->invitation_email ?? $contact->email;

        if ((string) $recipient === '') {
            throw new BusinessRuleException('There is no email address to send the portal link to.');
        }

        PortalAccessToken::query()->where('contact_id', $contact->id)->where('token_type', self::TokenType)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $issued = $this->tokens->createToken($contact, self::TokenType, $supplier, CarbonImmutable::now()->addDays((int) config('supplier_portal.token_days')));

        Mail::to((string) $recipient)->send(new SupplierPortalAccessMail(rtrim((string) config('supplier_portal.portal_url'), '/').'?token='.$issued['token'], $issued['token']));

        $this->ledger->record('SUPPLIER_PORTAL_ACCESS_ISSUED', $onboarding, ['supplier_id' => $supplier->id]);
    }

    /**
     * Resolves a token into the supplier it was issued for; the tenant of the token becomes the active one.
     */
    public function supplierFor(string $token): Supplier
    {
        $access = $this->tokens->resolveToken($token, self::TokenType);

        return Supplier::query()->where('tenant_id', $access->tenant_id)->where(static fn ($query) => $query->where('contact_id', $access->contact_id)->orWhere('id', $access->contact_id))->first()
            ?? abort(404, 'Invalid or expired portal link.');
    }
}
