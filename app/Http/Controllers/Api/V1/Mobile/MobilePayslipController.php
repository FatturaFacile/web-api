<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Events\Mobile\PayslipRead;
use App\Http\Controllers\Api\V1\Mobile\Concerns\ResolvesMobileContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PayslipDocument;
use App\Models\User;
use App\Services\Mobile\MobilePushService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Employee document drawer: payslips (LUL), CU and Swiss Lohnausweis with read receipts. */
class MobilePayslipController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    private const PUSH = [
        'it' => 'Nuovo documento disponibile',
        'de' => 'Neues Dokument verfügbar',
        'fr' => 'Nouveau document disponible',
        'en' => 'New document available',
    ];

    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', PayslipDocument::TYPES)],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $docs = PayslipDocument::where('user_id', $request->user()->id)
            ->whereNotNull('published_at')
            ->when(isset($params['type']), fn ($q) => $q->where('document_type', $params['type']))
            ->when(isset($params['year']), fn ($q) => $q->where('period_year', $params['year']))
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->paginate(50);

        return $this->successResponse($docs->items(), meta: ['current_page' => $docs->currentPage(), 'last_page' => $docs->lastPage(), 'total' => $docs->total()]);
    }

    /** Streams the file and records the read receipt (first open only). */
    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $doc = $this->ownDocument($request, $id);

        if (! Storage::disk('local')->exists($doc->file_path)) {
            return $this->problemDetailsResponse('Document Missing', 'The document file is not available.', Response::HTTP_NOT_FOUND);
        }

        $this->markRead($request, $doc);

        return Storage::disk('local')->download($doc->file_path, $doc->title.'.pdf');
    }

    /** Explicit read receipt (e.g. the app opened a locally cached copy). */
    public function readReceipt(Request $request, string $id): JsonResponse
    {
        $doc = $this->markRead($request, $this->ownDocument($request, $id));

        return $this->successResponse(['id' => $doc->id, 'read_at' => $doc->read_at], 'Read receipt recorded');
    }

    /** HR: publish a document to an employee's drawer and notify them. */
    public function store(Request $request, MobilePushService $push): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-payslips');

        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'document_type' => ['required', 'in:'.implode(',', PayslipDocument::TYPES)],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['nullable', 'integer', 'between:1,12'],
            'title' => ['required', 'string', 'max:191'],
            'country' => ['nullable', 'in:IT,CH'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $tenantId = (string) app(TenantContext::class)->getTenantId();
        $path = $request->file('file')->store("payslips/{$tenantId}/{$data['user_id']}", 'local');

        $doc = PayslipDocument::create([
            'user_id' => $data['user_id'],
            'document_type' => $data['document_type'],
            'period_year' => $data['period_year'],
            'period_month' => $data['period_month'] ?? null,
            'title' => $data['title'],
            'country' => $data['country'] ?? null,
            'file_path' => $path,
            'published_at' => now(),
        ]);

        $lang = substr((string) User::whereKey($data['user_id'])->value('locale'), 0, 2);
        $push->sendToUser($data['user_id'], self::PUSH[$lang] ?? self::PUSH['en'], $data['title'], [
            'type' => 'document_published',
            'document_id' => $doc->id,
            'document_type' => $doc->document_type,
        ]);

        return $this->successResponse($doc, 'Document published', Response::HTTP_CREATED);
    }

    private function ownDocument(Request $request, string $id): PayslipDocument
    {
        return PayslipDocument::where('user_id', $request->user()->id)
            ->whereNotNull('published_at')
            ->findOrFail($id);
    }

    private function markRead(Request $request, PayslipDocument $doc): PayslipDocument
    {
        if ($doc->read_at === null) {
            $doc->forceFill([
                'read_at' => now(),
                'read_ip' => $request->ip(),
                'read_device_id' => $this->currentDevice($request)?->device_id,
            ])->save();

            PayslipRead::dispatch($doc);
        }

        return $doc;
    }
}
