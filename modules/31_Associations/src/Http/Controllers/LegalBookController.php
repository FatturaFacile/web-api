<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\Associations\Http\Requests\ReasonRequest;
use Modules\Associations\Http\Requests\StoreProtocolEntryRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\ProtocolEntry;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\LegalBookService;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Services\ProtocolService;
use Modules\Associations\Support\AssociationCapability;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The books of the association, generated from the records, the protocol register and the searchable archive.
 */
final class LegalBookController extends AssociationController
{
    public function __construct(private readonly LegalBookService $books) {}

    #[AssociationGet('/api/v1/associations/books/{book}', 'showAssociationBook', 'A book of the association: members with the history of every year, regular volunteers, meetings or protocol', query: ['year'], response: 'AssociationBook')]
    public function show(Request $request, string $book): JsonResponse
    {
        $this->allow(AssociationCapability::Read);

        return response()->json(['data' => $this->books->book($book, $this->year($request))]);
    }

    #[AssociationGet('/api/v1/associations/books/{book}/csv', 'exportAssociationBookCsv', 'A book as CSV', query: ['year'], file: true)]
    public function csv(Request $request, string $book): Response
    {
        $this->allow(AssociationCapability::Read);

        return response($this->books->csv($book, $this->year($request)), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^a-z]/', '', $book).'.csv"',
        ]);
    }

    #[AssociationGet('/api/v1/associations/books/{book}/pdf', 'exportAssociationBookPdf', 'A book as PDF', query: ['year'], file: true)]
    public function printable(Request $request, string $book): Response
    {
        $this->allow(AssociationCapability::Read);

        return $this->pdf($this->books->pdf($book, $this->year($request)), "{$book}.pdf");
    }

    #[AssociationGet('/api/v1/associations/archive', 'searchAssociationArchive', 'Search meetings, minutes and protocol by date, body, participant and text', query: ['q', 'from', 'to', 'body', 'member_id'], response: 'AssociationArchiveResults')]
    public function archive(Request $request): JsonResponse
    {
        $this->allow(AssociationCapability::Read);
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:200'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'body' => ['sometimes', 'nullable', 'in:assembly,board,other'],
            'member_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        return response()->json(['data' => $this->books->search($filters)]);
    }

    #[AssociationGet('/api/v1/associations/protocol', 'listAssociationProtocol', 'The protocol register of a year', query: ['year', 'per_page'], response: 'AssociationRecord')]
    public function protocol(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Read);

        return AssociationRecordResource::collection(ProtocolEntry::query()
            ->when($this->year($request) !== null, fn ($query) => $query->where('fiscal_year', $this->year($request)))
            ->orderByDesc('fiscal_year')->orderByDesc('sequential_number')
            ->gridPaginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/protocol', 'registerAssociationProtocolEntry', 'Register incoming, outgoing or internal correspondence with the next number of the year', 'AssociationProtocolEntryRequest', multipart: true, response: 'AssociationRecord')]
    public function register(StoreProtocolEntryRequest $request, ProtocolService $protocol): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $entry = $protocol->register($request->safe()->except('file'), $request->file('file'));

        return (new AssociationRecordResource($entry))->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/protocol/{id}/cancel', 'cancelAssociationProtocolEntry', 'Cancel a protocol entry with a reason; the number stays used', 'AssociationRequiredReasonRequest', response: 'AssociationRecord')]
    public function cancel(ReasonRequest $request, string $id, ProtocolService $protocol): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource($protocol->cancel(ProtocolEntry::query()->findOrFail($id), (string) $request->validated('reason')));
    }

    #[AssociationGet('/api/v1/associations/protocol/{id}/download', 'downloadAssociationProtocolDocument', 'Download the document of a protocol entry', file: true)]
    public function download(string $id, PrivateFiles $files): StreamedResponse
    {
        $this->allow(AssociationCapability::Read);
        $entry = ProtocolEntry::query()->findOrFail($id);

        return $files->download($entry->disk, $entry->path, $entry->original_name);
    }

    private function year(Request $request): ?int
    {
        $year = $request->integer('year');

        return $year >= 1900 && $year <= 2200 ? $year : null;
    }
}
