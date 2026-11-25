<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\CustomerSatisfaction\Http\Controllers\Concerns\InteractsWithSatisfaction;
use Modules\CustomerSatisfaction\Http\Requests\ListFiltersRequest;
use Modules\CustomerSatisfaction\Http\Resources\SurveyResponseResource;
use Modules\CustomerSatisfaction\Models\SurveyResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The answers: score, band, text and voice comments with their sentiment.
 */
final class ResponseController extends Controller
{
    use ApiResponse;
    use InteractsWithSatisfaction;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/customer-satisfaction/responses', operationId: 'customerSatisfactionListResponses', summary: 'List the survey answers', security: self::SECURITY, tags: ['Customer Satisfaction'], responses: [new OA\Response(response: 200, description: 'Answers, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', SurveyResponse::class);

        $page = SurveyResponse::query()
            ->with(['template', 'customer', 'recoveryCase'])
            ->when($request->input('q'), fn ($rows, $term) => $rows->whereRaw('LOWER(comment_text) LIKE ?', ['%'.mb_strtolower((string) $term).'%']))
            ->when($request->input('survey_type'), fn ($rows, $type) => $rows->where('survey_type', $type))
            ->when($request->has('is_detractor'), fn ($rows) => $rows->where('is_detractor', $request->boolean('is_detractor')))
            ->when($request->input('customer_id'), fn ($rows, $customer) => $rows->where('customer_id', $customer))
            ->when($request->input('department_code'), fn ($rows, $value) => $rows->where('department_code', $value))
            ->when($request->input('technician_id'), fn ($rows, $value) => $rows->where('technician_id', $value))
            ->when($request->input('product_line_code'), fn ($rows, $value) => $rows->where('product_line_code', $value))
            ->when($request->input('branch_code'), fn ($rows, $value) => $rows->where('branch_code', $value))
            ->when($request->input('from'), fn ($rows, $from) => $rows->where('initial_clicked_at', '>=', $from))
            ->when($request->input('to'), fn ($rows, $to) => $rows->where('initial_clicked_at', '<=', $to.' 23:59:59'))
            ->latest('initial_clicked_at')
            ->paginate($this->perPage());

        return $this->paged($page, SurveyResponseResource::class);
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/responses/{id}', operationId: 'customerSatisfactionShowResponse', summary: 'Show a survey answer', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Answer')])]
    public function show(string $id): JsonResponse
    {
        $response = SurveyResponse::query()->with(['template', 'customer', 'recoveryCase'])->findOrFail($id);
        Gate::authorize('view', $response);

        return $this->successResponse(new SurveyResponseResource($response), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/customer-satisfaction/responses/{id}/voice', operationId: 'customerSatisfactionDownloadVoice', summary: 'Download the voice comment of an answer', security: self::SECURITY, tags: ['Customer Satisfaction'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Audio file')])]
    public function voice(string $id): StreamedResponse
    {
        $response = SurveyResponse::query()->findOrFail($id);
        Gate::authorize('view', $response);

        $disk = Storage::disk((string) config('customer_satisfaction.voice_disk'));
        abort_if($response->voice_attachment_path === null || ! $disk->exists($response->voice_attachment_path), 404);

        return $disk->response($response->voice_attachment_path);
    }
}
