<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Associations\Http\Requests\RecordLex398EntryRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\Lex398Service;
use Modules\Associations\Support\AssociationCapability;

/**
 * The regime of Law 398/1991: the ceiling of the year and the simplified register of commercial income.
 */
final class Lex398Controller extends AssociationController
{
    public function __construct(private readonly Lex398Service $lex398) {}

    #[AssociationGet('/api/v1/associations/lex398/{year}/monitor', 'showAssociationLex398Ceiling', 'Commercial income of the year against the ceiling parameter of the year', response: 'AssociationLex398Monitor')]
    public function monitor(string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->lex398->monitor((int) $year)]);
    }

    #[AssociationGet('/api/v1/associations/lex398/{year}/register', 'showAssociationLex398Register', 'The simplified register of the year with totals by rate', response: 'AssociationLex398Register')]
    public function register(string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $register = $this->lex398->register((int) $year);

        return response()->json(['data' => [...$register, 'entries' => AssociationRecordResource::collection($register['entries'])]]);
    }

    #[AssociationGet('/api/v1/associations/lex398/{year}/register/csv', 'exportAssociationLex398Register', 'The simplified register of the year as CSV', file: true)]
    public function csv(string $year): Response
    {
        $this->allow(AssociationCapability::Read);

        return response($this->lex398->csv((int) $year), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"lex398-{$year}.csv\""]);
    }

    #[AssociationPost('/api/v1/associations/lex398/entries', 'recordAssociationLex398Entry', 'Record commercial income by hand; corrections are further entries', 'AssociationLex398EntryRequest', response: 'AssociationRecord')]
    public function record(RecordLex398EntryRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);

        return (new AssociationRecordResource($this->lex398->record($request->validated(), $this->actorId($request))))->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/lex398/{year}/sync-invoices', 'syncAssociationLex398Invoices', 'Copy into the register the posted invoices of the year not yet recorded')]
    public function sync(Request $request, string $year): JsonResponse
    {
        $this->allow(AssociationCapability::Finance);

        return response()->json(['data' => ['added' => $this->lex398->syncInvoices((int) $year, $this->actorId($request))]]);
    }
}
