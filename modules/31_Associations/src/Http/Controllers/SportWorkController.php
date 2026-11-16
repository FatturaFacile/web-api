<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Associations\Http\Requests\RecordSportCompensationRequest;
use Modules\Associations\Http\Requests\StoreFileRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\SportCompensation;
use Modules\Associations\Models\SportContract;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\CertificateService;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Services\SportWorkService;
use Modules\Associations\Support\AssociationCapability;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sports work: compensations, yearly thresholds per person, the single book of the services, the registry export,
 * insurance and certificate expiries, and the data for the accountant. Collaborators and contracts are maintained
 * through the catalogs.
 */
final class SportWorkController extends AssociationController
{
    public function __construct(private readonly SportWorkService $sport) {}

    #[AssociationGet('/api/v1/associations/sport/compensations', 'listAssociationSportCompensations', 'Compensations of a year or of a collaborator', query: ['year', 'collaborator_id', 'per_page'], response: 'AssociationRecord')]
    public function compensations(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Finance);

        return AssociationRecordResource::collection(SportCompensation::query()
            ->when($request->integer('year') > 0, static fn ($query) => $query->whereYear('paid_on', $request->integer('year')))
            ->when($request->filled('collaborator_id'), static fn ($query) => $query->where('collaborator_id', $request->string('collaborator_id')->toString()))
            ->orderByDesc('paid_on')
            ->gridPaginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/sport/compensations', 'recordAssociationSportCompensation', 'Record a compensation with the contribution and withholding figures of the consultant', 'AssociationSportCompensationRequest', response: 'AssociationRecord')]
    public function record(RecordSportCompensationRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);

        return (new AssociationRecordResource($this->sport->recordCompensation($request->validated())))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/sport/{year}/thresholds', 'showAssociationSportThresholds', 'Yearly compensation per person against the thresholds configured for the year', response: 'AssociationSportThresholds')]
    public function thresholds(string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);

        return response()->json(['data' => $this->sport->thresholds((int) $year)]);
    }

    #[AssociationGet('/api/v1/associations/sport/{year}/single-book', 'showAssociationSportSingleBook', 'The single book of the services of the year', response: 'AssociationBook')]
    public function singleBook(string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);

        return response()->json(['data' => $this->sport->singleBook((int) $year)]);
    }

    #[AssociationGet('/api/v1/associations/sport/{year}/single-book/csv', 'exportAssociationSportSingleBook', 'The single book of the services as CSV', file: true)]
    public function singleBookCsv(string $year): Response
    {
        $this->allow(AssociationCapability::Finance);

        return $this->csv($this->sport->singleBookCsv((int) $year), "single-book-{$year}.csv");
    }

    #[AssociationGet('/api/v1/associations/sport/{year}/accountant-csv', 'exportAssociationSportAccountantCsv', 'Compensations with contribution and withholding data for the accountant', file: true)]
    public function accountantCsv(string $year): Response
    {
        $this->allow(AssociationCapability::Finance);

        return $this->csv($this->sport->accountantCsv((int) $year), "sport-compensations-{$year}.csv");
    }

    #[AssociationGet('/api/v1/associations/sport/{year}/registry-csv', 'exportAssociationSportRegistry', 'Members and collaborators of the year, to transfer to the national register of sports bodies', file: true)]
    public function registryCsv(string $year): Response
    {
        $this->allow(AssociationCapability::Read);

        return $this->csv($this->sport->registryCsv((int) $year), "sport-registry-{$year}.csv");
    }

    #[AssociationGet('/api/v1/associations/sport/expiries', 'listAssociationSportExpiries', 'Insurance of collaborators and medical certificates expiring within the given days', query: ['days'], response: 'AssociationSportExpiries')]
    public function expiries(Request $request, CertificateService $certificates): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $days = max(1, min(365, $request->integer('days', 30)));

        return response()->json(['data' => [
            'insurance' => AssociationRecordResource::collection($this->sport->insuranceExpiries($days)),
            'medical_certificates_expiring' => $certificates->expiring($days)->count(),
            'medical_certificates_expired' => $certificates->expired()->count(),
        ]]);
    }

    #[AssociationPost('/api/v1/associations/sport-contracts/{id}/document', 'attachAssociationSportContract', 'Attach the signed contract of a collaborator', 'AssociationFileRequest', multipart: true, response: 'AssociationRecord')]
    public function attach(StoreFileRequest $request, string $id, PrivateFiles $files): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Finance);
        $contract = SportContract::query()->findOrFail($id);
        $stored = $files->store($request->file('file'), (string) $contract->tenant_id, 'sport-contracts');
        $contract->update(['disk' => $stored['disk'], 'path' => $stored['path'], 'original_name' => $stored['original_name']]);

        return new AssociationRecordResource($contract);
    }

    #[AssociationGet('/api/v1/associations/sport-contracts/{id}/document', 'downloadAssociationSportContract', 'Download the signed contract of a collaborator', file: true)]
    public function download(string $id, PrivateFiles $files): StreamedResponse
    {
        $this->allow(AssociationCapability::Finance);
        $contract = SportContract::query()->findOrFail($id);

        return $files->download($contract->disk, $contract->path, $contract->original_name);
    }

    private function csv(string $content, string $name): Response
    {
        return response($content, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}\""]);
    }
}
