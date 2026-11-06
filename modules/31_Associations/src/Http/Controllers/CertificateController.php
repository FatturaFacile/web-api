<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Associations\Http\Requests\StoreCertificateRequest;
use Modules\Associations\Http\Resources\CertificateResource;
use Modules\Associations\Models\MedicalCertificate;
use Modules\Associations\Models\Member;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\CertificateService;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Support\AssociationCapability;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Medical certificates. Everything here needs the health capability: an accountant cannot reach it.
 */
final class CertificateController extends AssociationController
{
    public function __construct(
        private readonly CertificateService $certificates,
        private readonly PrivateFiles $files,
    ) {}

    #[AssociationGet('/api/v1/associations/certificates/expired', 'listAssociationExpiredCertificates', 'List the expired certificates not yet replaced')]
    public function expired(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Health);

        return CertificateResource::collection($this->certificates->expired()->paginate($this->perPage($request)));
    }

    #[AssociationGet('/api/v1/associations/certificates/expiring', 'listAssociationExpiringCertificates', 'List the certificates ending within the alert days, or the given days', query: ['days'])]
    public function expiring(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Health);
        $days = $request->filled('days') ? $request->integer('days') : null;

        return CertificateResource::collection($this->certificates->expiring($days)->paginate($this->perPage($request)));
    }

    #[AssociationGet('/api/v1/associations/members/{id}/certificates', 'listAssociationMemberCertificates', 'List the certificates of a person')]
    public function index(string $id): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Health);

        return CertificateResource::collection(Member::query()->findOrFail($id)->certificates()->orderByDesc('expires_on')->get());
    }

    #[AssociationPost('/api/v1/associations/members/{id}/certificates', 'storeAssociationCertificate', 'Store a certificate and its scan on the private disk', 'AssociationCertificateRequest', multipart: true)]
    public function store(StoreCertificateRequest $request, string $id): CertificateResource
    {
        $this->allow(AssociationCapability::Health);

        return new CertificateResource($this->certificates->store(Member::query()->findOrFail($id), $request->validated(), $request->file('file')));
    }

    #[AssociationGet('/api/v1/associations/certificates/{id}/download', 'downloadAssociationCertificate', 'Download the scan of a certificate', file: true)]
    public function download(string $id): StreamedResponse
    {
        $this->allow(AssociationCapability::Health);
        $certificate = MedicalCertificate::query()->findOrFail($id);

        return $this->files->download($certificate->disk, $certificate->path, $certificate->original_name);
    }

    #[AssociationDelete('/api/v1/associations/certificates/{id}', 'revokeAssociationCertificate', 'Withdraw a certificate: it stops counting')]
    public function destroy(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Health);
        MedicalCertificate::query()->findOrFail($id)->update(['revoked_at' => now()]);

        return response()->json(null, 204);
    }
}
