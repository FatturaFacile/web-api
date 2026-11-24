<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\PortalAccessToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Models\Credential;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\MemberDocument;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\Receipt;

/**
 * Erasure of a person of the registry. Records that a law obliges the association to keep (fiscal documents, the
 * members book) hold back the identity they need for the retention period; everything else is deleted or blanked. The
 * retention periods are legal parameters: when one is not configured the record is kept, because guessing a shorter
 * period could destroy evidence the association must hold.
 *
 * Append-only tables (access log, ballots, approved minutes) are never touched: they reference the person by an
 * identifier only, and that identifier no longer leads to a name once the registry row is anonymized.
 */
final class ErasureService
{
    public function __construct(private readonly LegalParameterService $parameters) {}

    /**
     * @return list<array{record: string, count: int, reason: string, until: ?string}>
     */
    public function holds(Member $member, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $holds = [];

        $receipts = Receipt::query()->where('member_id', $member->id)->get(['issued_on']);

        if ($receipts->isNotEmpty()) {
            $holds[] = $this->hold('fee_receipts', $receipts->count(), $receipts->max('issued_on'), LegalParameterService::FiscalRetentionYears, $today);
        }

        if (Schema::hasTable('assoc_donations')) {
            $donations = DB::table('assoc_donations')->join('assoc_donors', 'assoc_donors.id', '=', 'assoc_donations.donor_id')
                ->where('assoc_donors.member_id', $member->id)->whereNull('assoc_donations.deleted_at')->get(['assoc_donations.received_on']);

            if ($donations->isNotEmpty()) {
                $holds[] = $this->hold('donations', $donations->count(), $donations->max('received_on'), LegalParameterService::FiscalRetentionYears, $today);
            }
        }

        if (Schema::hasTable('assoc_sport_compensations')) {
            $compensations = DB::table('assoc_sport_compensations')->join('assoc_sport_collaborators', 'assoc_sport_collaborators.id', '=', 'assoc_sport_compensations.collaborator_id')
                ->where('assoc_sport_collaborators.member_id', $member->id)->whereNull('assoc_sport_compensations.deleted_at')->get(['assoc_sport_compensations.paid_on']);

            if ($compensations->isNotEmpty()) {
                $holds[] = $this->hold('sport_compensations', $compensations->count(), $compensations->max('paid_on'), LegalParameterService::FiscalRetentionYears, $today);
            }
        }

        $memberships = Membership::query()->where('member_id', $member->id)->count();

        if ($memberships > 0) {
            $left = $member->left_on ?? ($member->status === MemberStatus::Active ? $today : null);
            $holds[] = $this->hold('members_book', $memberships, $left, LegalParameterService::MembersBookRetentionYears, $today);
        }

        return array_values(array_filter($holds, static fn (array $hold): bool => $hold['until'] === null || $hold['until'] >= $today->toDateString()));
    }

    /**
     * Erases what can be erased now and reports what is kept and why.
     *
     * @return array{mode: string, erased: list<string>, kept: list<array{record: string, count: int, reason: string, until: ?string}>}
     */
    public function erase(Member $member): array
    {
        $holds = $this->holds($member);

        return DB::transaction(function () use ($member, $holds): array {
            $erased = $this->eraseAccessoryData($member);

            if ($holds === []) {
                $this->anonymize($member);
                $erased[] = 'identity';

                return ['mode' => 'full', 'erased' => $erased, 'kept' => []];
            }

            $member->update(['processing_restricted_at' => $member->processing_restricted_at ?? now()]);

            return ['mode' => 'partial', 'erased' => $erased, 'kept' => $holds];
        });
    }

    /**
     * @return list<string>
     */
    private function eraseAccessoryData(Member $member): array
    {
        $member->update([
            'health_notes' => null,
            'allergies' => null,
            'emergency_contact' => null,
            'identity_document' => null,
            'internal_notes' => null,
            'custom_fields' => [],
            'birth_place' => null,
            'gender' => null,
        ]);

        foreach (MemberDocument::query()->where('member_id', $member->id)->get() as $document) {
            $this->deleteFile($document->disk, $document->path);
            $document->forceDelete();
        }

        foreach (MedicalCertificate::query()->where('member_id', $member->id)->get() as $certificate) {
            $this->deleteFile($certificate->disk, $certificate->path);
            $certificate->forceDelete();
        }

        Credential::query()->where('member_id', $member->id)->get()->each->forceDelete();

        $contact = $member->contact;
        $contact->update(['phone' => null]);
        PortalAccessToken::query()->where('contact_id', $contact->id)->delete();

        return ['health_and_notes', 'documents', 'medical_certificates', 'credentials', 'phone', 'area_access'];
    }

    private function anonymize(Member $member): void
    {
        $member->contact->update(['first_name' => 'Anonymised', 'last_name' => substr($member->id, 0, 8), 'email' => null, 'company_name' => null]);
        $member->contact->addresses()->delete();
        $member->update([
            'tax_code' => null,
            'birth_date' => null,
            'status' => MemberStatus::Ceased,
            'erased_at' => now(),
        ]);
    }

    /**
     * @return array{record: string, count: int, reason: string, until: ?string}
     */
    private function hold(string $record, int $count, mixed $lastDate, string $parameter, CarbonImmutable $today): array
    {
        $last = $lastDate === null ? null : CarbonImmutable::parse((string) $lastDate);
        $years = $last === null ? null : $this->parameters->value($parameter, (int) $today->year);

        if ($last === null || $years === null) {
            return ['record' => $record, 'count' => $count, 'reason' => $last === null ? 'still_in_use' : 'retention_period_not_configured', 'until' => null];
        }

        return ['record' => $record, 'count' => $count, 'reason' => 'legal_retention', 'until' => $last->addYears((int) $years)->endOfYear()->toDateString()];
    }

    private function deleteFile(?string $disk, ?string $path): void
    {
        if ($disk !== null && $path !== null) {
            Storage::disk($disk)->delete($path);
        }
    }
}
