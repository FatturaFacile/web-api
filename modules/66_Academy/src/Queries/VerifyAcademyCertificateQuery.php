<?php

declare(strict_types=1);

namespace Modules\Academy\Queries;

use Enterprise\MultiTenant\Scopes\TenantScope;
use Modules\Academy\Domain\Enums\AcademyLocale;
use Modules\Academy\Models\AcademyCertificate;

/**
 * Public verification of a certificate by its code. It crosses tenants on purpose (the verifier has no account) and
 * discloses only what is printed on the certificate: holder, track, XP and issue date; never the tenant or the user id.
 */
final class VerifyAcademyCertificateQuery
{
    /**
     * @return array{valid: bool, certificate_code: string, holder_name: string, track_code: string, track_title: string, total_xp: int, issued_at: string}|null
     */
    public function execute(string $certificateCode, AcademyLocale $locale): ?array
    {
        $certificate = AcademyCertificate::query()
            ->withoutGlobalScope(TenantScope::class)
            ->with('track')
            ->where('certificate_code', strtoupper(trim($certificateCode)))
            ->first();

        if ($certificate === null || $certificate->track === null) {
            return null;
        }

        return [
            'valid' => true,
            'certificate_code' => $certificate->certificate_code,
            'holder_name' => $certificate->holder_name,
            'track_code' => $certificate->track->code,
            'track_title' => $certificate->track->text('title', $locale),
            'total_xp' => $certificate->total_xp,
            'issued_at' => $certificate->issued_at->toIso8601String(),
        ];
    }
}
