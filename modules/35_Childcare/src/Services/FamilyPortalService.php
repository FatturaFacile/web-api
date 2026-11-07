<?php

declare(strict_types=1);

namespace Modules\Childcare\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Modules\Childcare\Mail\FamilyAccessMail;
use Modules\Childcare\Models\Child;
use Modules\Childcare\Models\ChildDocument;
use Modules\Childcare\Models\ReferencePerson;

/**
 * The family portal: personal links for the guardians, on the magic links of the customer portal with a dedicated
 * token type, and what a guardian may see and do there.
 */
final class FamilyPortalService
{
    public const string TokenType = 'childcare_family';

    public function __construct(private readonly PortalTokenService $tokens) {}

    /**
     * Mails a personal link to a guardian. The token itself is only ever in the mail.
     */
    public function issueAccess(ReferencePerson $person): void
    {
        if (! $person->is_guardian || $person->portal_contact_id === null) {
            throw new BusinessRuleException('Only a guardian with a portal contact gets access to the family portal.');
        }

        $contact = Contact::query()->findOrFail($person->portal_contact_id);

        if ((string) $contact->email === '') {
            throw new BusinessRuleException('The portal contact has no email address.');
        }

        PortalAccessToken::query()->where('contact_id', $contact->id)->where('token_type', self::TokenType)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        $issued = $this->tokens->createToken($contact, self::TokenType, expiresAt: CarbonImmutable::now()->addDays((int) config('childcare.family_token_days')));

        Mail::to((string) $contact->email)->send(new FamilyAccessMail(rtrim((string) config('childcare.family_portal_url'), '/').'?token='.$issued['token'], $issued['token']));
    }

    /**
     * Resolves a token into the contact of the guardian; the tenant of the token becomes the active one.
     */
    public function contactFor(string $token): Contact
    {
        $accessToken = $this->tokens->resolveToken($token, self::TokenType);

        return Contact::query()->findOrFail($accessToken->contact_id);
    }

    /**
     * @return Collection<int, Child>
     */
    public function childrenOf(Contact $contact): Collection
    {
        $childIds = ReferencePerson::query()->where('portal_contact_id', $contact->id)->where('is_guardian', true)->pluck('child_id');

        return Child::query()->whereIn('id', $childIds)->orderBy('reference_code')->get();
    }

    public function childOf(Contact $contact, string $childId): Child
    {
        return $this->childrenOf($contact)->firstWhere('id', $childId) ?? abort(404);
    }

    public function sign(Contact $contact, ChildDocument $document, string $signedName): ChildDocument
    {
        $this->childOf($contact, $document->child_id);

        if ($document->status === 'signed') {
            throw new BusinessRuleException('The document is already signed.');
        }

        $document->update(['status' => 'signed', 'signed_name' => $signedName, 'signed_at' => now(), 'signed_by_contact_id' => $contact->id]);

        return $document;
    }
}
