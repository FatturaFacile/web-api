<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Associations\Enums\AccessReason;
use Modules\Associations\Enums\CertificateKind;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\AssociationSetting;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;

/**
 * Medical certificates: storage, expiry lists and the check that blocks access and competitions.
 */
final class CertificateService
{
    public function __construct(private readonly PrivateFiles $files) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function store(Member $member, array $data, ?UploadedFile $file): MedicalCertificate
    {
        $stored = $file === null ? [] : $this->files->store($file, (string) $member->tenant_id, "certificates/{$member->id}");

        return MedicalCertificate::create([
            ...$stored,
            'member_id' => $member->id,
            'discipline_id' => $data['discipline_id'] ?? null,
            'kind' => $data['kind'],
            'issued_on' => $data['issued_on'] ?? null,
            'expires_on' => $data['expires_on'],
            'physician' => $data['physician'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * What is wrong with the certificates of a person for a discipline, or null when they are fine. A certificate
     * without a discipline counts for all of them; a competitive one also covers a non-competitive requirement.
     */
    public function problem(Member $member, ?string $disciplineId, CertificateKind $required, ?CarbonImmutable $on = null): ?AccessReason
    {
        $latest = MedicalCertificate::query()
            ->inForce()
            ->where('member_id', $member->id)
            ->whereIn('kind', $required->acceptedValues())
            ->where(static fn (Builder $query) => $query->whereNull('discipline_id')->when($disciplineId !== null, static fn (Builder $inner) => $inner->orWhere('discipline_id', $disciplineId)))
            ->orderByDesc('expires_on')
            ->first();

        if ($latest === null) {
            return AccessReason::CertificateMissing;
        }

        return $latest->expires_on->lessThan($on ?? CarbonImmutable::today()) ? AccessReason::CertificateExpired : null;
    }

    /**
     * @throws AssociationException when the requirement of a course or a competition is not met
     */
    public function assertCovered(Member $member, ?string $disciplineId, ?CertificateKind $required): void
    {
        if ($required !== null && $this->problem($member, $disciplineId, $required) !== null) {
            throw new AssociationException(__('associations.certificate_required'));
        }
    }

    /**
     * Certificates past their date and not replaced by a later one.
     *
     * @return Builder<MedicalCertificate>
     */
    public function expired(): Builder
    {
        return $this->current()->whereDate('expires_on', '<', CarbonImmutable::today()->toDateString());
    }

    /**
     * Certificates ending within the alert days of the company.
     *
     * @return Builder<MedicalCertificate>
     */
    public function expiring(?int $withinDays = null): Builder
    {
        $today = CarbonImmutable::today();
        $days = $withinDays ?? AssociationSetting::current()->certificate_alert_days;

        return $this->current()
            ->whereDate('expires_on', '>=', $today->toDateString())
            ->whereDate('expires_on', '<=', $today->addDays($days)->toDateString());
    }

    /**
     * @return Builder<MedicalCertificate>
     */
    private function current(): Builder
    {
        return MedicalCertificate::query()
            ->inForce()
            ->with(['member.contact', 'discipline'])
            ->whereNotExists(static fn ($query) => $query->select(DB::raw(1))
                ->from('assoc_medical_certificates as newer')
                ->whereColumn('newer.member_id', 'assoc_medical_certificates.member_id')
                ->whereColumn('newer.kind', 'assoc_medical_certificates.kind')
                ->whereRaw('newer.discipline_id IS NOT DISTINCT FROM assoc_medical_certificates.discipline_id')
                ->whereColumn('newer.expires_on', '>', 'assoc_medical_certificates.expires_on')
                ->whereNull('newer.revoked_at')
                ->whereNull('newer.deleted_at'))
            ->orderBy('expires_on');
    }
}
